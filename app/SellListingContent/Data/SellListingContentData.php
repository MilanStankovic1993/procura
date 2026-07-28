<?php

namespace App\SellListingContent\Data;

final readonly class SellListingContentData
{
    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $unknownFacts
     * @param  list<string>  $warnings
     * @param  list<string>  $verificationActions
     */
    public function __construct(
        public string $title,
        public string $description,
        public int $completenessBasisPoints,
        public array $facts,
        public array $reasonCodes,
        public array $unknownFacts,
        public array $warnings,
        public array $verificationActions,
    ) {}
}
