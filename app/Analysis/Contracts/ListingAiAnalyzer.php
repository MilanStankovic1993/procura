<?php

namespace App\Analysis\Contracts;

use App\Analysis\Data\AiAnalysisData;
use App\Analysis\Data\AnalysisInputData;

interface ListingAiAnalyzer
{
    public function analyze(AnalysisInputData $input): AiAnalysisData;
}
