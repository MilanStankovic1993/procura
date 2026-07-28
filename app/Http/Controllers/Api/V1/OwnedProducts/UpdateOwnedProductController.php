<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\UpdateOwnedProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\UpdateOwnedProductRequest;
use App\Http\Resources\V1\OwnedProductResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class UpdateOwnedProductController extends Controller
{
    public function __invoke(
        UpdateOwnedProductRequest $request,
        string $ownedProduct,
        OrganizationContext $context,
        UpdateOwnedProduct $ownedProducts,
    ): JsonResource {
        $record = OwnedProduct::query()
            ->forOrganization($context->organization())
            ->findOrFail($ownedProduct);
        Gate::authorize('update', $record);

        $updated = $ownedProducts
            ->update($record, $request->user(), $request->validated())
            ->load(['category', 'targetCountries'])
            ->loadCount(['images', 'snapshots']);

        return new OwnedProductResource($updated);
    }
}
