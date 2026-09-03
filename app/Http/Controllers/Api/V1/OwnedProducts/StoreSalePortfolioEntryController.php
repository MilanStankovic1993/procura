<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\CreateSalePortfolioEntry;
use App\Actions\OwnedProducts\SalePortfolioProjection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreSalePortfolioEntryRequest;
use App\Http\Resources\V1\SalePortfolioProjectionResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreSalePortfolioEntryController extends Controller
{
    public function __invoke(
        StoreSalePortfolioEntryRequest $request,
        string $ownedProduct,
        OrganizationContext $context,
        CreateSalePortfolioEntry $entries,
        SalePortfolioProjection $projection,
    ): JsonResponse {
        $organization = $context->organization();
        $product = OwnedProduct::query()
            ->forOrganization($organization)
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $product);
        $result = $entries->create(
            $organization,
            $request->user(),
            $product->getKey(),
            $request->validated(),
        );
        $resource = new SalePortfolioProjectionResource(
            $projection->for($product),
        );

        return response()->json([
            'data' => $resource->resolve($request),
            'meta' => [
                'created' => $result['created'],
                'entry_id' => $result['entry']->getKey(),
            ],
        ], $result['created'] ? 201 : 200);
    }
}
