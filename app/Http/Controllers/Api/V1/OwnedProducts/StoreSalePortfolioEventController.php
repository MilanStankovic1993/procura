<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\RecordSalePortfolioEvent;
use App\Actions\OwnedProducts\SalePortfolioProjection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreSalePortfolioEventRequest;
use App\Http\Resources\V1\SalePortfolioEventResource;
use App\Http\Resources\V1\SalePortfolioProjectionResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreSalePortfolioEventController extends Controller
{
    public function __invoke(
        StoreSalePortfolioEventRequest $request,
        string $ownedProduct,
        string $salePortfolioEntry,
        OrganizationContext $context,
        RecordSalePortfolioEvent $events,
        SalePortfolioProjection $projection,
    ): JsonResponse {
        $organization = $context->organization();
        $product = OwnedProduct::query()
            ->forOrganization($organization)
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $product);
        $result = $events->record(
            $organization,
            $request->user(),
            $product->getKey(),
            $salePortfolioEntry,
            $request->validated(),
        );
        $projectionResource = new SalePortfolioProjectionResource(
            $projection->for($product),
        );
        $eventResource = new SalePortfolioEventResource($result['event']);

        return response()->json([
            'data' => $projectionResource->resolve($request),
            'meta' => [
                'created' => $result['created'],
                'event' => $eventResource->resolve($request),
            ],
        ], $result['created'] ? 201 : 200);
    }
}
