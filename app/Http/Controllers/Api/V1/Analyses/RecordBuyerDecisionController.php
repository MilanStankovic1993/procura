<?php

namespace App\Http\Controllers\Api\V1\Analyses;

use App\Actions\Analyses\RecordBuyerDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Analyses\RecordBuyerDecisionRequest;
use App\Http\Resources\V1\AnalysisResource;
use App\Http\Resources\V1\BuyerDecisionEventResource;
use App\Models\Analysis;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RecordBuyerDecisionController extends Controller
{
    public function __invoke(
        RecordBuyerDecisionRequest $request,
        string $analysis,
        OrganizationContext $context,
        RecordBuyerDecision $recordDecision,
    ): JsonResponse {
        $record = Analysis::query()
            ->forOrganization($context->organization())
            ->findOrFail($analysis);
        Gate::authorize('submit', $record);
        $result = $recordDecision->record(
            $context->organization(),
            $request->user(),
            $record,
            $request->validated(),
        );
        $result['analysis']->load($this->relations());
        $result['analysis']->loadCount('buyerDecisionEvents');
        $result['event']->load([
            'actor:id,name',
            'dealScore:id,run_number,score,recommendation',
        ]);
        $resource = new AnalysisResource($result['analysis']);
        $eventResource = new BuyerDecisionEventResource($result['event']);

        return response()->json([
            'data' => $resource->resolve($request),
            'meta' => [
                'created' => $result['created'],
                'event' => $eventResource->resolve($request),
            ],
        ], $result['created'] ? 201 : 200);
    }

    /**
     * @return array<string|int, mixed>
     */
    private function relations(): array
    {
        return [
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
                ->limit((int) config('buyer_decisions.history_limit')),
        ];
    }
}
