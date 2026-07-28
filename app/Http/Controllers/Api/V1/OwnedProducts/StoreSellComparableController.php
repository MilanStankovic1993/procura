<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\CreateSellComparable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreSellComparableRequest;
use App\Http\Resources\V1\SellComparableMutationResource;
use App\Models\MarketplaceSource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreSellComparableController extends Controller
{
    public function __invoke(
        StoreSellComparableRequest $request,
        string $ownedProduct,
        OrganizationContext $context,
        CreateSellComparable $comparables,
    ): JsonResponse {
        $organization = $context->organization();
        $record = OwnedProduct::query()
            ->forOrganization($organization)
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $record);
        $source = MarketplaceSource::query()
            ->where('key', $request->validated('marketplace_source_key'))
            ->firstOrFail();
        $result = $comparables->create(
            organization: $organization,
            actor: $request->user(),
            ownedProductId: $record->getKey(),
            source: $source,
            attributes: $request->validated(),
        );
        $resource = new SellComparableMutationResource($result);

        return response()->json([
            'data' => $resource->resolve($request),
            'meta' => ['created' => $result['created']],
        ], $result['created'] ? 201 : 200);
    }
}
