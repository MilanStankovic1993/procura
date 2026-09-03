<?php

namespace App\Privacy;

use LogicException;

final class PrivacyWorkflowConfiguration
{
    public function workflowVersion(): string
    {
        return $this->version('workflow_version');
    }

    public function privacyNoticeVersion(): string
    {
        return $this->version('privacy_notice_version');
    }

    public function responseTargetDays(): int
    {
        $days = (int) config('privacy.response_target_days');

        if ($days < 1 || $days > 365) {
            throw new LogicException(
                'Privacy response target days must be between 1 and 365.',
            );
        }

        return $days;
    }

    public function fulfillmentEnabled(): bool
    {
        return (bool) config('privacy.fulfillment.enabled');
    }

    public function fulfillmentExecutionVersion(): string
    {
        return $this->version('fulfillment.execution_version');
    }

    public function dataInventoryVersion(): string
    {
        return $this->version('fulfillment.data_inventory_version');
    }

    public function maximumExportBytes(): int
    {
        $bytes = (int) config(
            'privacy.fulfillment.maximum_export_bytes',
        );

        if ($bytes < 1 || $bytes > 10_737_418_240) {
            throw new LogicException(
                'Privacy export maximum bytes must be between 1 and 10737418240.',
            );
        }

        return $bytes;
    }

    public function maximumArtifactRetentionDays(): int
    {
        $days = (int) config(
            'privacy.fulfillment.maximum_artifact_retention_days',
        );

        if ($days < 1 || $days > 365) {
            throw new LogicException(
                'Privacy export artifact retention must be between 1 and 365 days.',
            );
        }

        return $days;
    }

    public function erasureEnabled(): bool
    {
        return (bool) config('privacy.erasure.enabled');
    }

    public function erasureExecutionVersion(): string
    {
        return $this->version('erasure.execution_version');
    }

    public function erasureDataInventoryVersion(): string
    {
        return $this->version('erasure.data_inventory_version');
    }

    public function maximumBackupRetentionDays(): int
    {
        $days = (int) config(
            'privacy.erasure.maximum_backup_retention_days',
        );

        if ($days < 1 || $days > 3650) {
            throw new LogicException(
                'Privacy backup retention must be between 1 and 3650 days.',
            );
        }

        return $days;
    }

    private function version(string $key): string
    {
        $version = trim((string) config("privacy.{$key}"));

        if ($version === '' || mb_strlen($version) > 100) {
            throw new LogicException(
                "Privacy configuration {$key} must contain 1 to 100 characters.",
            );
        }

        return $version;
    }
}
