<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Operations\Capacity\CapacityBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;

final class CapacityBaselineCommand extends Command
{
    protected $signature = 'operations:capacity-baseline
        {--organization= : Include one tenant Analysis index probe}
        {--enforce-duration : Fail configured database and wall-time budgets}
        {--allow-production-read-only : Explicitly permit bounded production diagnostics}
        {--json : Emit exactly one machine-readable JSON document}';

    protected $description = 'Measure bounded operational query and duration budgets';

    /**
     * @throws JsonException
     */
    public function handle(CapacityBaseline $baseline): int
    {
        if (
            app()->environment('production')
            && ! (bool) $this->option('allow-production-read-only')
        ) {
            return $this->failBeforeMeasurement(
                'production_confirmation_required',
                'Production capacity diagnostics require explicit confirmation.',
            );
        }

        $organization = $this->resolveOrganization();

        if ($organization === false) {
            return self::FAILURE;
        }

        $report = $baseline->measure(
            organization: $organization,
            enforceDuration: (bool) $this->option('enforce-duration'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->table(
                [
                    'Probe',
                    'Status',
                    'Queries',
                    'DB ms',
                    'Wall ms',
                    'Result count',
                ],
                collect($report->probes)
                    ->map(
                        fn ($probe): array => [
                            $probe->name,
                            $probe->status,
                            $probe->queryCount,
                            round($probe->databaseMilliseconds, 3),
                            round($probe->wallMilliseconds, 3),
                            $probe->resultCount ?? '-',
                        ],
                    )
                    ->values()
                    ->all(),
            );

            if ($report->passed()) {
                $this->components->info(
                    'All measured capacity probes passed.',
                );
            } else {
                $this->components->error(
                    'One or more capacity probes failed.',
                );
            }
        }

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function resolveOrganization(): Organization|false|null
    {
        $identifier = $this->option('organization');

        if ($identifier === null || $identifier === '') {
            return null;
        }

        if (
            ! is_string($identifier)
            || ! Str::isUlid($identifier)
        ) {
            $this->failBeforeMeasurement(
                'invalid_organization',
                'The organization identifier must be a valid ULID.',
            );

            return false;
        }

        $organization = Organization::query()->find($identifier);

        if ($organization === null) {
            $this->failBeforeMeasurement(
                'organization_not_found',
                'The organization was not found.',
            );

            return false;
        }

        return $organization;
    }

    private function failBeforeMeasurement(
        string $code,
        string $message,
    ): int {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                [
                    'status' => 'failed',
                    'error_code' => $code,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
