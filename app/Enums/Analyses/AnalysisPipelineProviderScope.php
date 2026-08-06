<?php

namespace App\Enums\Analyses;

enum AnalysisPipelineProviderScope: string
{
    case Rehearsal = 'rehearsal';
    case ProductionShaped = 'production_shaped';
}
