<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\SellListingDraftProjection;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SellListingDraftProjectionResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ShowSellListingDraftsController extends Controller
{
    public function __invoke(
        string $ownedProduct,
        OrganizationContext $context,
        SellListingDraftProjection $projection,
    ): JsonResource {
        $record = OwnedProduct::query()
            ->forOrganization($context->organization())
            ->findOrFail($ownedProduct);
        Gate::authorize('view', $record);

        return new SellListingDraftProjectionResource(
            $projection->for($record),
        );
    }
}
