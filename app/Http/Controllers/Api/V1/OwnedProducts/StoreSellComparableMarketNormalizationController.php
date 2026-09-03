<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\CreateSellComparableMarketNormalization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreSellComparableMarketNormalizationRequest;
use App\Http\Resources\V1\SellComparableMarketNormalizationResource;
use App\Http\Resources\V1\SellComparableSelectionResource;
use App\Http\Resources\V1\SellPriceBandResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class StoreSellComparableMarketNormalizationController extends Controller
{
    public function __invoke(
        StoreSellComparableMarketNormalizationRequest $request,
        string $ownedProduct,
        string $comparable,
        OrganizationContext $context,
        CreateSellComparableMarketNormalization $createNormalization,
    ): JsonResponse {
        $record = OwnedProduct::query()
            ->forOrganization($context->organization())
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $record);
        $result = $createNormalization->create(
            $context->organization(),
            $request->user(),
            $record->getKey(),
            $comparable,
            $request->validated(),
        );
        $normalization = new SellComparableMarketNormalizationResource(
            $result['normalization'],
        );
        $selection = new SellComparableSelectionResource(
            $result['selection']->loadMissing('items'),
        );
        $priceBand = new SellPriceBandResource(
            $result['price_band']->loadMissing(['items', 'selection.items']),
        );

        return response()->json([
            'data' => $normalization->resolve($request),
            'meta' => [
                'created' => $result['created'],
                'selection' => $selection->resolve($request),
                'price_band' => $priceBand->resolve($request),
            ],
        ], $result['created'] ? 201 : 200);
    }
}
