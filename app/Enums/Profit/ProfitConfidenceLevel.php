<?php

namespace App\Enums\Profit;

enum ProfitConfidenceLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function fromBasisPoints(int $basisPoints): self
    {
        return match (true) {
            $basisPoints >= 7500 => self::High,
            $basisPoints >= 5000 => self::Medium,
            default => self::Low,
        };
    }
}
