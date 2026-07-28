<?php

namespace App\Analysis\Data;

use InvalidArgumentException;

final readonly class AiAnalysisData
{
    /**
     * @param  array<string, mixed>  $normalizedListing
     * @param  list<string>  $needsInput
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $normalizedListing,
        public int $confidenceBasisPoints,
        public array $needsInput = [],
        public array $warnings = [],
    ) {
        if ($confidenceBasisPoints < 0 || $confidenceBasisPoints > 10000) {
            throw new InvalidArgumentException('AI confidence must be between 0 and 10000 basis points.');
        }

        foreach ([...$needsInput, ...$warnings] as $message) {
            if (! is_string($message) || trim($message) === '') {
                throw new InvalidArgumentException('AI messages must be non-empty strings.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'normalized_listing' => $this->normalizedListing,
            'confidence_basis_points' => $this->confidenceBasisPoints,
            'needs_input' => $this->needsInput,
            'warnings' => $this->warnings,
        ];
    }
}
