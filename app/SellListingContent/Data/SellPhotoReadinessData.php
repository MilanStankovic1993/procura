<?php

namespace App\SellListingContent\Data;

use App\Enums\Sell\SellPhotoReadinessStatus;

final readonly class SellPhotoReadinessData
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $warnings
     * @param  list<string>  $verificationActions
     */
    public function __construct(
        public SellPhotoReadinessStatus $status,
        public int $basisPoints,
        public array $items,
        public array $reasonCodes,
        public array $warnings,
        public array $verificationActions,
    ) {}
}
