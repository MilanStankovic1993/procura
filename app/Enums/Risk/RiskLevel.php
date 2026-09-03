<?php

namespace App\Enums\Risk;

enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score <= 24 => self::Low,
            $score <= 49 => self::Medium,
            $score <= 74 => self::High,
            default => self::Critical,
        };
    }
}
