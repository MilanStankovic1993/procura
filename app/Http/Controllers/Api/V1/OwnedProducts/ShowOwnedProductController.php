<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OwnedProductResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ShowOwnedProductController extends Controller
{
    public function __invoke(
        string $ownedProduct,
        OrganizationContext $context,
    ): JsonResource {
        $record = OwnedProduct::query()
            ->forOrganization($context->organization())
            ->with([
                'category',
                'targetCountries',
                'images',
                'snapshots' => static fn ($query) => $query
                    ->with(['capturedBy', 'category'])
                    ->limit(25),
                'assessments' => static fn ($query) => $query
                    ->with([
                        'snapshot',
                        'assessedBy',
                        'productModel.category',
                        'productVariant',
                    ])
                    ->limit(25),
            ])
            ->withCount(['images', 'snapshots', 'assessments'])
            ->findOrFail($ownedProduct);

        Gate::authorize('view', $record);

        return new OwnedProductResource($record);
    }
}
