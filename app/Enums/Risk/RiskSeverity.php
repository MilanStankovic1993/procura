<?php

namespace App\Enums\Risk;

enum RiskSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
