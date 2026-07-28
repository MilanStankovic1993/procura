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
