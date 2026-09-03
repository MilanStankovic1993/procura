<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\StoreOwnedProductImages;
use App\Enums\OwnedProducts\OwnedProductImageKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreOwnedProductImagesRequest;
use App\Http\Resources\V1\OwnedProductImageResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StoreOwnedProductImagesController extends Controller
{
    public function __invoke(
        StoreOwnedProductImagesRequest $request,
        string $ownedProduct,
        OrganizationContext $context,
        StoreOwnedProductImages $images,
    ): AnonymousResourceCollection {
        $record = OwnedProduct::query()
            ->forOrganization($context->organization())
            ->findOrFail($ownedProduct);
        Gate::authorize('manageImages', $record);

        return OwnedProductImageResource::collection(
            $images->store(
                $record,
                $request->user(),
                OwnedProductImageKind::from($request->validated('kind')),
                $request->file('images'),
            ),
        );
    }
}
