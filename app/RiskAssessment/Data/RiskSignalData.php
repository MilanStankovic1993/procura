<?php

namespace App\RiskAssessment\Data;

use App\Enums\Risk\RiskCategory;
use App\Enums\Risk\RiskSeverity;
use InvalidArgumentException;

final readonly class RiskSignalData
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $code,
        public RiskCategory $category,
        public RiskSeverity $severity,
        public bool $isUnknown,
        public int $weightPoints,
        public int $scoreContribution,
        public array $evidence,
        public string $source,
        public int $confidenceBasisPoints,
        public ?string $verificationAction,
    ) {
        if (
            preg_match('/^[a-z0-9_]+$/', $code) !== 1
            || $source === ''
        ) {
            throw new InvalidArgumentException(
                'Risk signal codes and sources must be explicit stable identifiers.',
            );
        }

        if (
            $weightPoints < 0
            || $weightPoints > 100
            || $scoreContribution < 0
            || $scoreContribution > $weightPoints
        ) {
            throw new InvalidArgumentException(
                'Risk signal weights and contributions must be bounded points.',
            );
        }

        if ($confidenceBasisPoints < 0 || $confidenceBasisPoints > 10000) {
            throw new InvalidArgumentException(
                'Risk signal confidence must be between 0 and 10000 basis points.',
            );
        }

        if ($isUnknown && $scoreContribution !== 0) {
            throw new InvalidArgumentException(
                'Unknown facts may reduce confidence but cannot silently add risk points.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'is_unknown' => $this->isUnknown,
            'weight_points' => $this->weightPoints,
            'score_contribution' => $this->scoreContribution,
            'evidence_snapshot' => $this->evidence,
            'source' => $this->source,
            'confidence_basis_points' => $this->confidenceBasisPoints,
            'verification_action' => $this->verificationAction,
        ];
    }
}
