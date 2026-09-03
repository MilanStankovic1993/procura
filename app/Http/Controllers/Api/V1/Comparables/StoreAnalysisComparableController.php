<?php

namespace App\Http\Controllers\Api\V1\Comparables;

use App\Actions\Analyses\CreateAnalysisComparable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Comparables\StoreComparableRequest;
use App\Http\Resources\V1\ComparableRecordResource;
use App\Http\Resources\V1\ComparableSetResource;
use App\Models\Analysis;
use App\Models\MarketplaceSource;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreAnalysisComparableController extends Controller
{
    public function __invoke(
        StoreComparableRequest $request,
        string $analysis,
        OrganizationContext $context,
        CreateAnalysisComparable $createComparable,
    ): JsonResponse {
        $record = Analysis::query()
            ->forOrganization($context->organization())
            ->findOrFail($analysis);
        Gate::authorize('submit', $record);
        $attributes = $request->validated();
        $source = MarketplaceSource::query()
            ->where('key', $attributes['marketplace_source_key'])
            ->where('active', true)
            ->firstOrFail();
        $result = $createComparable->create(
            $context->organization(),
            $request->user(),
            $record,
            $source,
            $attributes,
        );
        $resource = new ComparableRecordResource($result['record']);
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
