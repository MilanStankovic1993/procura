<?php

namespace App\BrokerRequests\Operations;

use RuntimeException;

final class BrokerOperationsConfiguration
{
    public function requestAgeHours(): int
    {
        return $this->boundedInteger(
            'broker.monitoring.request_age_hours',
            1,
            8760,
        );
    }

    public function offerExpiryGraceHours(): int
    {
        return $this->boundedInteger(
            'broker.monitoring.offer_expiry_grace_hours',
            0,
            168,
        );
    }

    public function transactionAgeHours(): int
    {
        return $this->boundedInteger(
            'broker.monitoring.transaction_age_hours',
            1,
            8760,
        );
    }

    public function commissionAgeHours(): int
    {
        return $this->boundedInteger(
            'broker.monitoring.commission_age_hours',
            1,
            8760,
        );
    }

    public function reportPurgeGraceHours(): int
    {
        return $this->boundedInteger(
            'broker.monitoring.report_purge_grace_hours',
            0,
            168,
        );
    }

    public function paymentCaseAgeHours(): int
    {
        return $this->boundedInteger(
            'broker.monitoring.payment_case_age_hours',
            1,
            8760,
        );
    }

    /**
     * @return array{
     *     request_age_hours: int,
     *     offer_expiry_grace_hours: int,
     *     transaction_age_hours: int,
     *     commission_age_hours: int,
     *     report_purge_grace_hours: int,
     *     payment_case_age_hours: int
     * }
     */
    public function thresholds(): array
    {
        return [
            'request_age_hours' => $this->requestAgeHours(),
            'offer_expiry_grace_hours' => $this->offerExpiryGraceHours(),
            'transaction_age_hours' => $this->transactionAgeHours(),
            'commission_age_hours' => $this->commissionAgeHours(),
            'report_purge_grace_hours' => $this->reportPurgeGraceHours(),
            'payment_case_age_hours' => $this->paymentCaseAgeHours(),
        ];
    }

    private function boundedInteger(
        string $key,
        int $minimum,
        int $maximum,
    ): int {
        $value = config($key);

        if (
            ! is_int($value)
            || $value < $minimum
            || $value > $maximum
        ) {
            throw new RuntimeException(
                "The {$key} broker monitoring setting is invalid.",
            );
        }

        return $value;
    }
}
