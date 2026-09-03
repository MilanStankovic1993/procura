<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\CreateOwnedProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\StoreOwnedProductRequest;
use App\Http\Resources\V1\OwnedProductResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreOwnedProductController extends Controller
{
    public function __invoke(
        StoreOwnedProductRequest $request,
        OrganizationContext $context,
        CreateOwnedProduct $ownedProducts,
    ): JsonResponse {
        $organization = $context->organization();
        Gate::authorize('create', [OwnedProduct::class, $organization]);

        $ownedProduct = $ownedProducts->create(
            $organization,
            $request->user(),
            $request->validated(),
        )
            ->load(['category', 'targetCountries'])
            ->loadCount(['images', 'snapshots']);

        return (new OwnedProductResource($ownedProduct))
            ->response()
            ->setStatusCode(201);
    }
}
