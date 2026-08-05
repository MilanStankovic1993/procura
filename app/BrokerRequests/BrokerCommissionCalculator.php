<?php

namespace App\BrokerRequests;

use App\Enums\Validation\ApplicationValidationCode;
use App\Support\Validation\ApplicationValidation;

final class BrokerCommissionCalculator
{
    public const MAX_SAFE_MINOR = 9007199254740991;

    /**
     * @return array{
     *     commission_rule_version: string,
     *     commission_rate_basis_points: int,
     *     commission_base_minor: int,
     *     commission_amount_minor: int,
     *     payable_total_minor: int
     * }
     */
    public function calculate(
        int $baseMinor,
        string $ruleVersion,
        int $rateBasisPoints,
        ApplicationValidationCode $invalidRuleCode = (
            ApplicationValidationCode::BrokerCommissionConfigurationInvalid
        ),
    ): array {
        $ruleVersion = trim($ruleVersion);

        if (
            $baseMinor < 0
            || $baseMinor > self::MAX_SAFE_MINOR
            || $ruleVersion === ''
            || mb_strlen($ruleVersion) > 64
            || $rateBasisPoints < 1
            || $rateBasisPoints > 10000
        ) {
            ApplicationValidation::fail(
                'broker_offer',
                $invalidRuleCode,
            );
        }

        $whole = intdiv($baseMinor, 10000) * $rateBasisPoints;
        $remainder = intdiv(
            (($baseMinor % 10000) * $rateBasisPoints) + 5000,
            10000,
        );
        $amount = $this->safeTotal([$whole, $remainder]);
        $payable = $this->safeTotal([$baseMinor, $amount]);

        return [
            'commission_rule_version' => $ruleVersion,
            'commission_rate_basis_points' => $rateBasisPoints,
            'commission_base_minor' => $baseMinor,
            'commission_amount_minor' => $amount,
            'payable_total_minor' => $payable,
        ];
    }

    /**
     * @param  list<int>  $amounts
     */
    public function safeTotal(array $amounts): int
    {
        $total = 0;

        foreach ($amounts as $amount) {
            if ($amount < 0 || $amount > self::MAX_SAFE_MINOR - $total) {
                ApplicationValidation::fail(
                    'total_minor',
                    ApplicationValidationCode::BrokerOfferMoneyLimit,
                );
            }

            $total += $amount;
        }

        return $total;
    }
}
