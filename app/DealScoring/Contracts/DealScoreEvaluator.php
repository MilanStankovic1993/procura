<?php

namespace App\DealScoring\Contracts;

use App\DealScoring\Data\DealScoreData;
use App\DealScoring\Data\DealScoreInputData;

interface DealScoreEvaluator
{
    public function evaluate(DealScoreInputData $input): DealScoreData;
}
