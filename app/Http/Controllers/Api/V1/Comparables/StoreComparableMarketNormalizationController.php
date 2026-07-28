<?php

namespace App\Http\Controllers\Api\V1\Comparables;

use App\Actions\Analyses\CreateComparableMarketNormalization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Comparables\StoreComparableMarketNormalizationRequest;
use App\Http\Resources\V1\ComparableMarketNormalizationResource;
use App\Http\Resources\V1\ComparableSetResource;
use App\Models\Analysis;
use App\Models\ComparableRecord;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreComparableMarketNormalizationController extends Controller
{
    public function __invoke(
        StoreComparableMarketNormalizationRequest $request,
        string $analysis,
        string $comparable,
        OrganizationContext $context,
        CreateComparableMarketNormalization $createNormalization,
    ): JsonResponse {
        $analysisRecord = Analysis::query()
            ->forOrganization($context->organization())
            ->findOrFail($analysis);
        Gate::authorize('submit', $analysisRecord);
        $comparableRecord = ComparableRecord::query()
            ->forOrganization($context->organization())
            ->findOrFail($comparable);
        $result = $createNormalization->create(
            $context->organization(),
            $request->user(),
            $analysisRecord,
            $comparableRecord,
            $request->validated(),
        );
        $resource = new ComparableMarketNormalizationResource(
            $result['normalization'],
        );
        $setResource = new ComparableSetResource(
            $result['set']->loadMissing('items'),
        );

        return response()->json([
            'data' => $resource->resolve($request),
            'meta' => [
                'created' => $result['created'],
                'comparable_set' => $setResource->resolve($request),
            ],
        ], $result['created'] ? 201 : 200);
    }
}
