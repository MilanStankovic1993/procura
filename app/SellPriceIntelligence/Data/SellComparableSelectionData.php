<?php

namespace App\SellPriceIntelligence\Data;

use App\Enums\Comparables\ComparableSetStatus;
use InvalidArgumentException;

final readonly class SellComparableSelectionData
{
    /**
     * @param  list<array<string, mixed>>  $included
     * @param  list<array<string, mixed>>  $excluded
     * @param  list<string>  $reasonCodes
     * @param  array<string, mixed>  $inputSnapshot
     */
    public function __construct(
        public ComparableSetStatus $status,
        public string $selectorVersion,
        public string $inputHash,
        public string $targetCountryCode,
        public string $targetCurrencyCode,
        public int $candidateCount,
        public int $minimumRequired,
        public array $included,
        public array $excluded,
        public array $reasonCodes,
        public array $inputSnapshot,
    ) {
        if ($candidateCount !== count($included) + count($excluded)) {
            throw new InvalidArgumentException(
                'Sell comparable candidate totals must match item evidence.',
            );
        }

        if (
            ($status === ComparableSetStatus::Ready)
            !== (count($included) >= $minimumRequired)
        ) {
            throw new InvalidArgumentException(
                'Sell comparable status must match its minimum evidence count.',
            );
        }
    }
}
