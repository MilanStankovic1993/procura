<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\OutcomeTrackingProjection;
use App\Actions\OwnedProducts\RecordActualCostSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreActualCostSnapshotRequest;
use App\Http\Resources\V1\ActualCostSnapshotResource;
use App\Http\Resources\V1\OutcomeTrackingProjectionResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreActualCostSnapshotController extends Controller
{
    public function __invoke(
        StoreActualCostSnapshotRequest $request,
        string $ownedProduct,
        OrganizationContext $context,
        RecordActualCostSnapshot $costs,
        OutcomeTrackingProjection $projection,
    ): JsonResponse {
        $organization = $context->organization();
        $product = OwnedProduct::query()
            ->forOrganization($organization)
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $product);
        $result = $costs->record(
            $organization,
            $request->user(),
            $product->getKey(),
            $request->validated(),
        );

        return response()->json([
            'data' => (new OutcomeTrackingProjectionResource(
                $projection->for($product),
            ))->resolve($request),
            'meta' => [
                'created' => $result['created'],
                'cost_snapshot' => (new ActualCostSnapshotResource(
                    $result['snapshot'],
                ))->resolve($request),
            ],
        ], $result['created'] ? 201 : 200);
    }
}
