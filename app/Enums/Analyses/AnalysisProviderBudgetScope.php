<?php

namespace App\Enums\Analyses;

enum AnalysisProviderBudgetScope: string
{
    case Global = 'global';
    case Organization = 'organization';
    case User = 'user';
}
