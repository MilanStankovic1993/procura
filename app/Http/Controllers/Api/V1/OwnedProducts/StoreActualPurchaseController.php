<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\OutcomeTrackingProjection;
use App\Actions\OwnedProducts\RecordActualPurchase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreActualPurchaseRequest;
use App\Http\Resources\V1\ActualPurchaseResource;
use App\Http\Resources\V1\OutcomeTrackingProjectionResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreActualPurchaseController extends Controller
{
    public function __invoke(
        StoreActualPurchaseRequest $request,
        string $ownedProduct,
        OrganizationContext $context,
        RecordActualPurchase $purchases,
        OutcomeTrackingProjection $projection,
    ): JsonResponse {
        $organization = $context->organization();
        $product = OwnedProduct::query()
            ->forOrganization($organization)
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $product);
        $result = $purchases->record(
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
                'purchase' => (new ActualPurchaseResource(
                    $result['purchase'],
                ))->resolve($request),
            ],
        ], $result['created'] ? 201 : 200);
    }
}
