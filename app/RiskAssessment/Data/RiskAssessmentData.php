<?php

namespace App\RiskAssessment\Data;

use App\Enums\Risk\RiskAssessmentStatus;
use App\Enums\Risk\RiskConfidenceLevel;
use App\Enums\Risk\RiskLevel;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class RiskAssessmentData
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  array<string, int>  $confidenceComponents
     * @param  list<string>  $verificationActions
     * @param  array<string, mixed>  $inputSnapshot
     * @param  list<RiskSignalData>  $signals
     */
    public function __construct(
        public RiskAssessmentStatus $status,
        public string $evaluatorVersion,
        public string $inputHash,
        public CarbonImmutable $calculationAt,
        public int $score,
        public RiskLevel $level,
        public int $confidenceBasisPoints,
        public RiskConfidenceLevel $confidenceLevel,
        public array $reasonCodes,
        public array $confidenceComponents,
        public array $verificationActions,
        public array $inputSnapshot,
        public array $signals,
    ) {
        if (
            $score < 0
            || $score > 100
            || $level !== RiskLevel::fromScore($score)
        ) {
            throw new InvalidArgumentException(
                'Risk score must be bounded and match its documented level.',
            );
        }

        if (
            $confidenceBasisPoints < 0
            || $confidenceBasisPoints > 10000
            || $confidenceLevel !== RiskConfidenceLevel::fromBasisPoints(
                $confidenceBasisPoints,
            )
        ) {
            throw new InvalidArgumentException(
                'Risk confidence must be bounded and match its configured level.',
            );
        }

        if (count($signals) > (int) config('risk_assessment.max_signals')) {
            throw new InvalidArgumentException(
                'Risk signals exceeded the configured hard bound.',
            );
        }

        $contribution = array_sum(array_map(
            static fn (RiskSignalData $signal): int => $signal->scoreContribution,
            $signals,
        ));

        if ($score !== min(100, $contribution)) {
            throw new InvalidArgumentException(
                'Risk score must be reproducible from its persisted signal contributions.',
            );
        }
    }
}
