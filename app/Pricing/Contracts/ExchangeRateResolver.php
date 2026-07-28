<?php

namespace App\Pricing\Contracts;

use App\Pricing\Data\ExchangeRateResolutionData;
use Carbon\CarbonImmutable;

interface ExchangeRateResolver
{
    public function resolve(
        string $sourceCurrencyCode,
        string $targetCurrencyCode,
        CarbonImmutable $calculationAt,
    ): ExchangeRateResolutionData;
}
