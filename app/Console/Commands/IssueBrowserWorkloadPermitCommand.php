<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Operations\Capacity\BrowserWorkloadConfiguration;
use App\Operations\Capacity\BrowserWorkloadPermitStore;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

final class IssueBrowserWorkloadPermitCommand extends Command
{
    protected $signature = 'operations:issue-browser-workload-permit
        {--actor-email=* : Verified staging actors used by the browser workload}
        {--samples-per-scenario= : Cold browser samples for every approved scenario}
        {--ttl= : Permit lifetime in seconds}
        {--acknowledge-load : Confirm that the permit authorizes real staging browser and API traffic}
        {--allow-undersampled-rehearsal : Issue a non-evidence permit below the release minimum}
        {--json : Emit exactly one secret-free machine-readable JSON document}';

    protected $description = 'Issue a bounded staging-only browser performance workload permit';

    /** @throws JsonException */
    public function handle(
        BrowserWorkloadConfiguration $configuration,
        BrowserWorkloadPermitStore $permits,
    ): int {
        if (app()->environment('production')) {
            return $this->failBeforeIssuance(
                'production_forbidden',
                'Browser performance workloads are permanently forbidden in production.',
            );
        }

        if (! app()->environment('staging')) {
            return $this->failBeforeIssuance(
                'staging_environment_required',
                'Browser performance workload permits may only be issued in staging.',
            );
        }

        if (! (bool) $this->option('acknowledge-load')) {
            return $this->failBeforeIssuance(
                'load_acknowledgement_required',
                'Explicit acknowledgement of the real staging browser workload is required.',
            );
        }

        $stage = 'infrastructure';

        try {
            $configuration->assertStagingInfrastructure();
            $stage = 'sample_scope';
            $samples = $configuration->samplesPerScenario(
                $this->option('samples-per-scenario'),
            );
            $evidenceEligible = $samples >= $configuration->minimumEvidenceSamples();

            if (! $evidenceEligible && ! (bool) $this->option('allow-undersampled-rehearsal')) {
                throw new RuntimeException('The browser workload is below the evidence minimum.');
            }

            $ttl = $configuration->permitTtlSeconds($this->option('ttl'));
            $stage = 'actor_scope';
            $actors = $this->actors();
            $stage = 'contract';
            $contract = [
                'version' => 1,
                'origin' => $configuration->origin(),
                'actors' => count($actors),
                'samples_per_scenario' => $samples,
                'minimum_evidence_samples_per_scenario' => $configuration->minimumEvidenceSamples(),
                'scenarios' => $configuration->scenarios(),
                'budgets' => $configuration->budgets(),
                'profile' => $configuration->profile(),
                'evidence_eligible' => $evidenceEligible,
            ];
            $contractHash = hash('sha256', json_encode(
                $contract,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ));
            $stage = 'permit_storage';
            $permit = $permits->issue(
                actors: $actors,
                scenarios: $contract['scenarios'],
                samplesPerScenario: $samples,
                ttlSeconds: $ttl,
                contractHash: $contractHash,
            );
            $stage = 'private_file';
            $relativePath = $this->writePermitFile([
                ...$contract,
                'contract_hash' => $contractHash,
                'permit' => $permit['token'],
                'expires_at' => $permit['expires_at'],
                'authorizations' => $permit['authorizations'],
            ]);
        } catch (Throwable) {
            if (isset($permit['token']) && is_string($permit['token'])) {
                $permits->revoke($permit['token']);
            }

            return $this->failBeforeIssuance(
                "invalid_{$stage}",
                'The browser workload configuration, actor scope, or private permit file is invalid.',
            );
        }

        $report = [
            'status' => 'issued',
            'permit_file' => $relativePath,
            'expires_at' => $permit['expires_at'],
            'actors' => count($actors),
            'scenarios' => count($contract['scenarios']),
            'samples_per_scenario' => $samples,
            'authorizations' => $permit['authorizations'],
            'budget_version' => $contract['budgets']['version'],
            'profile_version' => $contract['profile']['version'],
            'evidence_eligible' => $evidenceEligible,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Status', 'Actors', 'Scenarios', 'Samples each', 'Expires', 'Private file'],
                [[
                    'issued',
                    count($actors),
                    count($contract['scenarios']),
                    $samples,
                    $permit['expires_at'],
                    $relativePath,
                ]],
            );
            $this->components->warn(
                'Treat the private permit file as a short-lived secret and delete it after the run.',
            );
        }

        return self::SUCCESS;
    }

    /** @return list<User> */
    private function actors(): array
    {
        $values = $this->option('actor-email');

        if (! is_array($values)) {
            throw new RuntimeException('The browser workload actor scope is invalid.');
        }

        $emails = collect($values)
            ->map(static fn (mixed $email): string => is_string($email)
                ? strtolower(trim($email))
                : '')
            ->filter(static fn (string $email): bool => filter_var(
                $email,
                FILTER_VALIDATE_EMAIL,
            ) !== false)
            ->unique()
            ->values();

        if ($emails->isEmpty() || $emails->count() > 20) {
            throw new RuntimeException('The browser workload actor scope is invalid.');
        }

        $actors = User::query()
            ->whereIn('email', $emails->all())
            ->with('memberships')
            ->get();
        $foundEmails = $actors
            ->map(static fn (User $actor): string => strtolower($actor->email))
            ->sort()
            ->values();

        if ($foundEmails->all() !== $emails->sort()->values()->all()) {
            throw new RuntimeException('The browser workload actor scope is invalid.');
        }

        foreach ($actors as $actor) {
            $membership = $actor->memberships->firstWhere(
                'organization_id',
                $actor->current_organization_id,
            );

            if (
                ! $actor->hasVerifiedEmail()
                || $actor->privacy_erased_at !== null
                || $actor->current_organization_id === null
                || $membership === null
                || $membership->joined_at === null
            ) {
                throw new RuntimeException('The browser workload actor scope is invalid.');
            }
        }

        return $actors->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function writePermitFile(array $payload): string
    {
        $filename = 'browser-workload-permit-'.bin2hex(random_bytes(8)).'.json';
        $directory = storage_path('app/private');
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        if (
            (! is_dir($directory) && ! mkdir($directory, 0700, true))
            || ! is_writable($directory)
        ) {
            throw new RuntimeException('The private browser permit directory is unavailable.');
        }

        $contents = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ).PHP_EOL;
        $previousMask = umask(0077);

        try {
            $handle = fopen($path, 'x');
        } finally {
            umask($previousMask);
        }

        if ($handle === false) {
            throw new RuntimeException('The private browser permit file could not be created.');
        }

        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('The private browser permit file could not be written.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);

            throw $exception;
        }

        fclose($handle);

        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($path, 0600)) {
            @unlink($path);

            throw new RuntimeException('The private browser permit permissions could not be secured.');
        }

        return 'storage/app/private/'.$filename;
    }

    /** @throws JsonException */
    private function failBeforeIssuance(string $code, string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => 'failed',
                'error_code' => $code,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
