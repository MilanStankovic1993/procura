<?php

namespace App\Enums\Risk;

enum RiskConfidenceLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function fromBasisPoints(int $basisPoints): self
    {
        return match (true) {
            $basisPoints < (int) config('risk_assessment.low_confidence_basis_points') => self::Low,
            $basisPoints < (int) config('risk_assessment.high_confidence_basis_points') => self::Medium,
            default => self::High,
        };
    }
}
