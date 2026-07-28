<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Enums\Analyses\AnalysisType;
use App\Enums\Profit\ProfitEstimateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\IndexEstimateAccuracyCandidateRequest;
use App\Http\Resources\V1\EstimateAccuracyCandidateResource;
use App\Models\Analysis;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class IndexEstimateAccuracyCandidateController extends Controller
{
    public function __invoke(
        IndexEstimateAccuracyCandidateRequest $request,
        string $ownedProduct,
        OrganizationContext $context,
    ): JsonResponse {
        $organization = $context->organization();
        $product = OwnedProduct::query()
            ->forOrganization($organization)
            ->findOrFail($ownedProduct);
        Gate::authorize('view', $product);
        $validated = $request->validated();
        $limit = (int) config('estimate_accuracy.candidate_limit');
        $query = Analysis::query()
            ->forOrganization($organization)
            ->where('analysis_type', AnalysisType::Buy->value)
            ->whereHas('currentProfitEstimate', function ($query): void {
                $query
                    ->where('status', '!=', ProfitEstimateStatus::NeedsInput->value)
                    ->where('unknown_count', 0)
                    ->whereNotNull('purchase_price_minor')
                    ->whereNotNull('additional_costs_minor')
                    ->whereNotNull('expected_net_profit_minor')
                    ->where('price_estimate_id', function ($query): void {
                        $query
                            ->select('id')
                            ->from('price_estimates as current_price_estimates')
                            ->whereColumn(
                                'current_price_estimates.analysis_id',
                                'profit_estimates.analysis_id',
                            )
                            ->orderByDesc('run_number')
                            ->orderByDesc('id')
                            ->limit(1);
                    })
                    ->where('risk_assessment_id', function ($query): void {
                        $query
                            ->select('id')
                            ->from('risk_assessments as current_risk_assessments')
                            ->whereColumn(
                                'current_risk_assessments.analysis_id',
                                'profit_estimates.analysis_id',
                            )
                            ->orderByDesc('run_number')
                            ->orderByDesc('id')
                            ->limit(1);
                    })
                    ->where('cost_input_id', function ($query): void {
                        $query
                            ->select('id')
                            ->from('cost_inputs as current_cost_inputs')
                            ->whereColumn(
                                'current_cost_inputs.analysis_id',
                                'profit_estimates.analysis_id',
                            )
                            ->orderByDesc('run_number')
                            ->orderByDesc('id')
                            ->limit(1);
                    });
            })
            ->with([
                'listing:id,title,marketplace_name',
                'currentPriceEstimate',
                'currentRiskAssessment',
                'currentCostInput',
                'currentProfitEstimate',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($validated['search'])) {
            $search = addcslashes($validated['search'], '\\%_');
            $query->where(function ($query) use ($search): void {
                $query
                    ->whereKey($search)
                    ->orWhereHas(
                        'listing',
                        fn ($query) => $query->where(
                            'title',
                            'like',
                            "%{$search}%",
                        ),
                    );
            });
        }

        $candidates = $query
            ->limit($limit)
            ->get()
            ->filter(static function (Analysis $analysis): bool {
                $estimate = $analysis->currentProfitEstimate;

                return $estimate !== null
                    && $analysis->currentPriceEstimate?->getKey()
                        === $estimate->price_estimate_id
                    && $analysis->currentRiskAssessment?->getKey()
                        === $estimate->risk_assessment_id
                    && $analysis->currentCostInput?->getKey()
                        === $estimate->cost_input_id;
            })
            ->take($limit)
            ->values();

        return response()->json([
            'data' => EstimateAccuracyCandidateResource::collection(
                $candidates,
            )->resolve($request),
            'meta' => [
                'count' => $candidates->count(),
                'limit' => $limit,
            ],
        ]);
    }
}
