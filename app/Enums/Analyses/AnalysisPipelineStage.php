<?php

namespace App\Enums\Analyses;

enum AnalysisPipelineStage: string
{
    case ProviderAnalysis = 'provider_analysis';
    case ProductMatching = 'product_matching';
    case ComparableSelection = 'comparable_selection';
    case PriceEstimation = 'price_estimation';
    case RiskAssessment = 'risk_assessment';
    case Finalization = 'finalization';

    public function durationColumn(): string
    {
        return $this->value.'_microseconds';
    }
}
