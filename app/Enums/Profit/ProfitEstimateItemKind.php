<?php

namespace App\Enums\Profit;

enum ProfitEstimateItemKind: string
{
    case Revenue = 'revenue';
    case Cost = 'cost';
}
