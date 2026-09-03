<?php

namespace App\OpportunityAssessment\Data;

use App\Enums\Opportunity\OpportunityAssessmentStatus;
use App\Enums\Opportunity\OpportunityComponent;
use App\Enums\Opportunity\OpportunityConfidenceLevel;
use Carbon\CarbonImmutable;

final readonly class OpportunityAssessmentData
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  array<string, int>  $confidenceComponents
     * @param  array<string, mixed>  $inputSnapshot
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public OpportunityComponent $component,
        public OpportunityAssessmentStatus $status,
        public string $evaluatorVersion,
        public string $inputHash,
        public CarbonImmutable $calculatedAt,
        public ?int $score,
        public int $confidenceBasisPoints,
        public OpportunityConfidenceLevel $confidenceLevel,
        public int $unknownCount,
        public array $reasonCodes,
        public array $confidenceComponents,
        public array $inputSnapshot,
        public array $items,
    ) {}
}
