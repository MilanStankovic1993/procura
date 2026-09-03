<?php

namespace App\ComparableSelection\Data;

use App\Enums\Comparables\ComparableSetStatus;
use InvalidArgumentException;

final readonly class ComparableSelectionData
{
    /**
     * @param  list<array<string, mixed>>  $included
     * @param  list<array<string, mixed>>  $excluded
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public ComparableSetStatus $status,
        public string $selectorVersion,
        public string $inputHash,
        public string $targetCountryCode,
        public ?string $targetCurrencyCode,
        public int $candidateCount,
        public int $minimumRequired,
        public array $included,
        public array $excluded,
        public array $reasonCodes,
    ) {
        if ($candidateCount !== count($included) + count($excluded)) {
            throw new InvalidArgumentException(
                'Comparable candidate totals must match included and excluded evidence.',
            );
        }

        if ($minimumRequired < 1) {
            throw new InvalidArgumentException(
                'Comparable selection requires a positive minimum.',
            );
        }

        if (
            $status === ComparableSetStatus::Ready
            && count($included) < $minimumRequired
        ) {
            throw new InvalidArgumentException(
                'A ready comparable set must satisfy its minimum.',
            );
        }

        if (
            $status === ComparableSetStatus::Insufficient
            && count($included) >= $minimumRequired
        ) {
            throw new InvalidArgumentException(
                'An insufficient comparable set cannot satisfy its minimum.',
            );
        }
    }
}
