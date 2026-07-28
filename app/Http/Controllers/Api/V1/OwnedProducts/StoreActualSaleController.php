<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\OutcomeTrackingProjection;
use App\Actions\OwnedProducts\RecordActualSale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreActualSaleRequest;
use App\Http\Resources\V1\ActualSaleResource;
use App\Http\Resources\V1\OutcomeTrackingProjectionResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreActualSaleController extends Controller
{
    public function __invoke(
        StoreActualSaleRequest $request,
        string $ownedProduct,
        string $salePortfolioEntry,
        OrganizationContext $context,
        RecordActualSale $sales,
        OutcomeTrackingProjection $projection,
    ): JsonResponse {
        $organization = $context->organization();
        $product = OwnedProduct::query()
            ->forOrganization($organization)
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $product);
        $result = $sales->record(
            $organization,
            $request->user(),
            $product->getKey(),
            $salePortfolioEntry,
            $request->validated(),
        );

        return response()->json([
            'data' => (new OutcomeTrackingProjectionResource(
                $projection->for($product),
            ))->resolve($request),
            'meta' => [
                'created' => $result['created'],
                'sale' => (new ActualSaleResource(
                    $result['sale'],
                ))->resolve($request),
            ],
        ], $result['created'] ? 201 : 200);
    }
}
