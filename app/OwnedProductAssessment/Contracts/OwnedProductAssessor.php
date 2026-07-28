<?php

namespace App\OwnedProductAssessment\Contracts;

use App\OwnedProductAssessment\Data\OwnedProductAssessmentData;
use App\OwnedProductAssessment\Data\OwnedProductAssessmentInputData;

interface OwnedProductAssessor
{
    public function assess(
        OwnedProductAssessmentInputData $input,
    ): OwnedProductAssessmentData;
}
