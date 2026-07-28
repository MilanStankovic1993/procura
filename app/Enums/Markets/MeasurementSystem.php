<?php

namespace App\Enums\Markets;

enum MeasurementSystem: string
{
    case Metric = 'metric';
    case UnitedStates = 'us_customary';
    case UnitedKingdom = 'uk_mixed';
}
