<?php

namespace App\Console\Commands;

use App\Operations\ProductionPreflight;
use Illuminate\Console\Command;
use JsonException;

final class ProductionPreflightCommand extends Command
{
    protected $signature = 'operations:production-preflight
        {--allow-non-production : Permit a staging/local configuration rehearsal}
        {--strict : Fail when any item still requires external review or activation}
        {--json : Emit exactly one machine-readable JSON document}';

    protected $description = 'Validate the effective configuration before production activation';

    /**
     * @throws JsonException
     */
    public function handle(ProductionPreflight $preflight): int
    {
        $report = $preflight->inspect(
            allowNonProduction: (bool) $this->option(
                'allow-non-production',
            ),
        );
        $strict = (bool) $this->option('strict');
        $successful = $strict
            ? $report->launchReady()
            : $report->deploymentReady();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report->operatorPayload(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return $successful ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Check', 'Status', 'Guidance'],
            array_map(
                static fn ($check): array => [
                    $check->key,
                    $check->status,
                    $check->message,
                ],
                $report->checks,
            ),
        );

        if ($successful) {
            $this->components->info(
                $strict
                    ? 'Procura passed the strict production launch preflight.'
                    : 'Procura passed the deployable configuration preflight.',
            );
        } else {
            $this->components->error(
                $strict
                    ? 'Procura is not ready for production launch.'
                    : 'Procura has blocking production configuration failures.',
            );
        }

        return $successful ? self::SUCCESS : self::FAILURE;
    }
}
