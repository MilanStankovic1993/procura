<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\CreateSellListingDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreSellListingDraftRequest;
use App\Http\Resources\V1\SellListingDraftResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreSellListingDraftController extends Controller
{
    public function __invoke(
        StoreSellListingDraftRequest $request,
        string $ownedProduct,
        OrganizationContext $context,
        CreateSellListingDraft $drafts,
    ): JsonResponse {
        $organization = $context->organization();
        $record = OwnedProduct::query()
            ->forOrganization($organization)
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $record);
        $result = $drafts->create(
            organization: $organization,
            actor: $request->user(),
            ownedProductId: $record->getKey(),
            attributes: $request->validated(),
        );
        $resource = new SellListingDraftResource($result['draft']);

        return response()->json([
            'data' => $resource->resolve($request),
            'meta' => ['created' => $result['created']],
        ], $result['created'] ? 201 : 200);
    }
}
