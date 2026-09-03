<?php

namespace App\DealScoring\Data;

use App\Enums\DealScoring\DealRecommendation;
use App\Enums\DealScoring\DealScoreConfidenceLevel;
use App\Enums\DealScoring\DealScoreStatus;
use Carbon\CarbonImmutable;

final readonly class DealScoreData
{
    /**
     * @param  list<array<string, mixed>>  $capDecisions
     * @param  list<string>  $reasonCodes
     * @param  array<string, int>  $confidenceComponents
     * @param  list<string>  $factorsIncreasing
     * @param  list<string>  $factorsReducing
     * @param  list<string>  $assumptions
     * @param  list<string>  $verificationActions
     * @param  array<string, mixed>  $inputSnapshot
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public DealScoreStatus $status,
        public string $calculationVersion,
        public string $inputHash,
        public CarbonImmutable $calculatedAt,
        public ?int $uncappedScore,
        public ?int $uncappedScoreBasisPoints,
        public ?int $score,
        public ?int $scoreBasisPoints,
        public DealRecommendation $recommendation,
        public int $confidenceBasisPoints,
        public DealScoreConfidenceLevel $confidenceLevel,
        public int $unknownCount,
        public ?int $applicableCap,
        public array $capDecisions,
        public array $reasonCodes,
        public array $confidenceComponents,
        public array $factorsIncreasing,
        public array $factorsReducing,
        public array $assumptions,
        public array $verificationActions,
        public array $inputSnapshot,
        public array $items,
    ) {}
}
