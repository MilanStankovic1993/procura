<?php

namespace App\Enums\EstimateAccuracy;

enum EstimateAccuracyStatus: string
{
    case Calculated = 'calculated';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
}
