<?php

namespace App\Http\Controllers\Api\V1\Comparables;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Comparables\IndexComparableRequest;
use App\Http\Resources\V1\ComparableRecordResource;
use App\Models\Analysis;
use App\Models\ComparableRecord;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class IndexAnalysisComparableController extends Controller
{
    public function __invoke(
        IndexComparableRequest $request,
        string $analysis,
        OrganizationContext $context,
    ): JsonResponse {
        $record = Analysis::query()
            ->forOrganization($context->organization())
            ->with('currentProductMatch')
            ->findOrFail($analysis);
        Gate::authorize('view', $record);
        $limit = (int) $request->validated('limit', 25);
        $productModelId = $record->currentProductMatch?->product_model_id;
        $records = $productModelId === null
            ? collect()
            : ComparableRecord::query()
                ->forOrganization($context->organization())
                ->where('product_model_id', $productModelId)
                ->with(['marketplaceSource', 'productVariant'])
                ->orderByDesc('observed_at')
                ->orderBy('id')
                ->limit($limit)
                ->get();

        return response()->json([
            'data' => ComparableRecordResource::collection($records)->resolve($request),
            'meta' => [
                'limit' => $limit,
                'product_model_id' => $productModelId,
            ],
        ]);
    }
}
