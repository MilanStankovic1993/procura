<?php

namespace App\Console\Commands;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\User;
use App\Operations\Capacity\AnalysisPipelineWorkloadConfiguration;
use App\Operations\Capacity\AnalysisPipelineWorkloadPermitStore;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

final class IssueAnalysisPipelineWorkloadPermitCommand extends Command
{
    protected $signature = 'operations:issue-analysis-workload-permit
        {--actor-email=* : Verified staging actors permitted to create and submit analyses}
        {--scenarios= : Total bounded analysis scenarios}
        {--ttl= : Permit lifetime in seconds}
        {--acknowledge-load : Confirm that the permit authorizes real staging API and queue traffic}
        {--allow-fake-provider-rehearsal : Issue a non-evidence permit for harness rehearsal only}
        {--json : Emit exactly one secret-free machine-readable JSON document}';

    protected $description = 'Issue a bounded staging-only Analysis pipeline workload permit';

    /**
     * @throws JsonException
     */
    public function handle(
        AnalysisPipelineWorkloadConfiguration $configuration,
        AnalysisPipelineWorkloadPermitStore $permits,
    ): int {
        if (app()->environment('production')) {
            return $this->failBeforeIssuance(
                'production_forbidden',
                'Analysis pipeline workloads are permanently forbidden in production.',
            );
        }

        if (! app()->environment('staging')) {
            return $this->failBeforeIssuance(
                'staging_environment_required',
                'Analysis pipeline workload permits may only be issued in staging.',
            );
        }

        if (! (bool) $this->option('acknowledge-load')) {
            return $this->failBeforeIssuance(
                'load_acknowledgement_required',
                'Explicit acknowledgement of the real staging workload is required.',
            );
        }

        $stage = 'infrastructure';

        try {
            $configuration->assertStagingInfrastructure();
            $stage = 'provider_scope';
            $evidenceEligible = $configuration->productionShapedProviders();

            if (
                ! $evidenceEligible
                && ! (bool) $this->option('allow-fake-provider-rehearsal')
            ) {
                throw new RuntimeException(
                    'Production-shaped providers are required for workload evidence.',
                );
            }

            $scenarios = $configuration->scenarios($this->option('scenarios'));
            $ttlSeconds = $configuration->permitTtlSeconds(
                $this->option('ttl'),
            );
            $stage = 'actor_scope';
            $actors = $this->actors();
            $stage = 'permit_storage';
            $permit = $permits->issue($actors, $scenarios, $ttlSeconds);
            $stage = 'private_file';
            $relativePath = $this->writePermitFile(
                token: $permit['token'],
                expiresAt: $permit['expires_at'],
                actorCount: count($actors),
                scenarios: $scenarios,
                mutationRequests: $permit['mutation_requests'],
                budgets: $configuration->budgets(),
                evidenceEligible: $evidenceEligible,
            );
        } catch (Throwable) {
            if (isset($permit['token']) && is_string($permit['token'])) {
                $permits->revoke($permit['token']);
            }

            return $this->failBeforeIssuance(
                "invalid_{$stage}",
                'The workload configuration, actor scope, or private permit file is invalid.',
            );
        }

        $report = [
            'status' => 'issued',
            'permit_file' => $relativePath,
            'expires_at' => $permit['expires_at'],
            'actors' => count($actors),
            'scenarios' => $scenarios,
            'mutation_requests' => $permit['mutation_requests'],
            'budget_version' => $configuration->budgets()['version'],
            'evidence_eligible' => $evidenceEligible,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->table(
                ['Status', 'Actors', 'Scenarios', 'Expires', 'Private file'],
                [[
                    'issued',
                    count($actors),
                    $scenarios,
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

    /**
     * @return list<User>
     */
    private function actors(): array
    {
        $values = $this->option('actor-email');

        if (! is_array($values)) {
            throw new RuntimeException('The workload actor scope is invalid.');
        }

        $emails = collect($values)
            ->map(static fn (mixed $email): string => is_string($email)
                ? strtolower(trim($email))
                : '')
            ->filter(static fn (string $email): bool => (
                filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ))
            ->unique()
            ->values();

        if ($emails->isEmpty() || $emails->count() > 50) {
            throw new RuntimeException('The workload actor scope is invalid.');
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
            throw new RuntimeException('The workload actor scope is invalid.');
        }

        foreach ($actors as $actor) {
            if (! $this->validActor($actor)) {
                throw new RuntimeException('The workload actor scope is invalid.');
            }
        }

        return $actors->all();
    }

    private function validActor(User $actor): bool
    {
        if (
            ! $actor->hasVerifiedEmail()
            || $actor->privacy_erased_at !== null
            || $actor->current_organization_id === null
        ) {
            return false;
        }

        $membership = $actor->memberships->firstWhere(
            'organization_id',
            $actor->current_organization_id,
        );

        return $membership !== null
            && $membership->role->allows(
                OrganizationPermission::ManageAnalyses,
            );
    }

    /**
     * @param  array<string, float|int|string>  $budgets
     *
     * @throws JsonException
     */
    private function writePermitFile(
        string $token,
        string $expiresAt,
        int $actorCount,
        int $scenarios,
        int $mutationRequests,
        array $budgets,
        bool $evidenceEligible,
    ): string {
        $filename = 'analysis-pipeline-workload-permit-'
            .bin2hex(random_bytes(8)).'.json';
        $directory = storage_path('app/private');
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        if (
            (! is_dir($directory) && ! mkdir($directory, 0700, true))
            || ! is_writable($directory)
        ) {
            throw new RuntimeException(
                'The private workload permit directory is unavailable.',
            );
        }

        $contents = json_encode([
            'version' => 1,
            'permit' => $token,
            'expires_at' => $expiresAt,
            'actors' => $actorCount,
            'scenarios' => $scenarios,
            'mutation_requests' => $mutationRequests,
            'budgets' => $budgets,
            'evidence_eligible' => $evidenceEligible,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $previousMask = umask(0077);

        try {
            $handle = fopen($path, 'x');
        } finally {
            umask($previousMask);
        }

        if ($handle === false) {
            throw new RuntimeException(
                'The private workload permit file could not be created.',
            );
        }

        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException(
                    'The private workload permit file could not be written.',
                );
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);

            throw $exception;
        }

        fclose($handle);

        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($path, 0600)) {
            @unlink($path);

            throw new RuntimeException(
                'The private workload permit permissions could not be secured.',
            );
        }

        return 'storage/app/private/'.$filename;
    }

    /**
     * @throws JsonException
     */
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
