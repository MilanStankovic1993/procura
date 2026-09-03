<?php

namespace App\BrokerRequests;

use App\Enums\Validation\ApplicationValidationCode;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class BrokerReportConfiguration
{
    public function __construct(
        public bool $enabled,
        public string $version,
        public string $disk,
        public int $retentionDays,
        public int $downloadTtlMinutes,
        public int $maxBytes,
        public int $purgeBatch,
    ) {}

    public static function load(): self
    {
        $configuration = new self(
            enabled: (bool) config('broker.reports_enabled', false),
            version: trim((string) config('broker.report_version', '')),
            disk: trim((string) config('broker.report_disk', '')),
            retentionDays: (int) config('broker.report_retention_days', 0),
            downloadTtlMinutes: (int) config(
                'broker.report_download_ttl_minutes',
                0,
            ),
            maxBytes: (int) config('broker.report_max_bytes', 0),
            purgeBatch: (int) config('broker.report_purge_batch', 0),
        );

        $configuration->assertValid();

        return $configuration;
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled) {
            ApplicationValidation::fail(
                'broker_report',
                ApplicationValidationCode::BrokerReportsDisabled,
            );
        }
    }

    private function assertValid(): void
    {
        $valid = $this->version !== ''
            && mb_strlen($this->version) <= 64
            && preg_match('/^[a-z0-9][a-z0-9:._-]+$/', $this->version) === 1
            && $this->disk !== ''
            && mb_strlen($this->disk) <= 64
            && $this->retentionDays >= 1
            && $this->retentionDays <= 3650
            && $this->downloadTtlMinutes >= 1
            && $this->downloadTtlMinutes <= 60
            && $this->maxBytes >= 1024
            && $this->maxBytes <= 10 * 1024 * 1024
            && $this->purgeBatch >= 1
            && $this->purgeBatch <= 500;

        try {
            Storage::disk($this->disk);
        } catch (Throwable) {
            $valid = false;
        }

        if (! $valid) {
            ApplicationValidation::fail(
                'broker_report',
                ApplicationValidationCode::BrokerReportConfigurationInvalid,
            );
        }
    }
}
