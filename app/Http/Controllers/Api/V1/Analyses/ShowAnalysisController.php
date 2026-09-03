<?php

namespace App\Http\Controllers\Api\V1\Analyses;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AnalysisResource;
use App\Models\Analysis;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ShowAnalysisController extends Controller
{
    public function __invoke(
        string $analysis,
        OrganizationContext $context,
    ): JsonResource {
        $record = Analysis::query()
            ->forOrganization($context->organization())
            ->with([
                'listing:id,title,marketplace_name,asking_price_minor,currency_code',
                'currentDispatch',
                'aiAnalyses' => static fn ($query) => $query->limit(10),
                'currentProductMatch.productModel.brand',
                'currentProductMatch.productModel.category',
                'currentProductMatch.productVariant',
                'currentComparableSet.items',
                'currentPriceEstimate.items',
                'currentRiskAssessment.signals',
                'currentCostInput.items',
                'currentProfitEstimate.items',
                'currentOpportunityInput.items',
                'currentLogisticsAssessment.items',
                'currentDemandAssessment.items',
                'currentDealScore.items',
                'currentBuyerDecisionEvent.actor:id,name',
                'currentBuyerDecisionEvent.dealScore:id,run_number,score,recommendation',
                'buyerDecisionEvents' => static fn ($query) => $query
                    ->with([
                        'actor:id,name',
                        'dealScore:id,run_number,score,recommendation',
                    ])
                    ->limit((int) config(
                        'buyer_decisions.history_limit',
                    )),
            ])
            ->withCount('buyerDecisionEvents')
            ->findOrFail($analysis);
        Gate::authorize('view', $record);

        return new AnalysisResource($record);
    }
}
