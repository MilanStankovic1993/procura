<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\DeleteOwnedProductImage;
use App\Http\Controllers\Controller;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DeleteOwnedProductImageController extends Controller
{
    public function __invoke(
        Request $request,
        string $ownedProduct,
        string $image,
        OrganizationContext $context,
        DeleteOwnedProductImage $images,
    ): Response {
        $record = OwnedProduct::query()
            ->forOrganization($context->organization())
            ->findOrFail($ownedProduct);
        $imageRecord = $record->images()->findOrFail($image);
        Gate::authorize('manageImages', $record);

        $images->delete($imageRecord, $request->user());

        return response()->noContent();
    }
}
