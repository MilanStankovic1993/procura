<?php

namespace App\Monitoring\Data;

use App\Enums\Monitoring\SavedSearchMatchStatus;

final readonly class SavedSearchMatchDecision
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $unknownCriteria
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public SavedSearchMatchStatus $status,
        public array $reasonCodes,
        public array $unknownCriteria,
        public array $evidence,
    ) {}
}
