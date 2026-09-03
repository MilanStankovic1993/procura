<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Actions\OwnedProducts\OutcomeTrackingProjection;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OutcomeTrackingProjectionResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ShowOutcomeTrackingController extends Controller
{
    public function __invoke(
        string $ownedProduct,
        OrganizationContext $context,
        OutcomeTrackingProjection $projection,
    ): JsonResource {
        $record = OwnedProduct::query()
            ->forOrganization($context->organization())
            ->findOrFail($ownedProduct);
        Gate::authorize('view', $record);

        return new OutcomeTrackingProjectionResource(
            $projection->for($record),
        );
    }
}
