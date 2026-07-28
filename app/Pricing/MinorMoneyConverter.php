<?php

namespace App\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use OverflowException;

class MinorMoneyConverter
{
    public const MAX_SAFE_MINOR = 9007199254740991;

    public function convert(
        int $sourceAmountMinor,
        int $sourceMinorUnit,
        int $targetMinorUnit,
        string $rateValue,
    ): int {
        if ($sourceAmountMinor < 0) {
            throw new InvalidArgumentException(
                'A monetary source amount cannot be negative.',
            );
        }

        if (
            $sourceMinorUnit < 0
            || $sourceMinorUnit > 9
            || $targetMinorUnit < 0
            || $targetMinorUnit > 9
        ) {
            throw new InvalidArgumentException(
                'Currency minor-unit precision is outside the supported range.',
            );
        }

        $rate = BigDecimal::of($rateValue);

        if ($rate->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException(
                'A conversion rate must be greater than zero.',
            );
        }

        $sourceScale = BigInteger::of(10)->power($sourceMinorUnit);
        $targetScale = BigInteger::of(10)->power($targetMinorUnit);
        $converted = BigDecimal::of($sourceAmountMinor)
            ->multipliedBy($rate)
            ->multipliedBy($targetScale)
            ->dividedBy($sourceScale, 0, RoundingMode::HalfEven);

        if (
            $converted->isGreaterThan(self::MAX_SAFE_MINOR)
            || $converted->isLessThan(0)
        ) {
            throw new OverflowException(
                'The converted amount exceeds the supported integer-minor-unit range.',
            );
        }

        return $converted->toInt();
    }
}
