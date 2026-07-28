<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MarketplaceImportResource;
use App\Models\MarketplaceImport;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class ShowMarketplaceImportController extends Controller
{
    public function __invoke(
        string $marketplaceImport,
        OrganizationContext $context,
    ): JsonResource {
        $import = MarketplaceImport::query()
            ->forOrganization($context->organization())
            ->with([
                'marketplaceSource',
                'createdBy:id,name',
                'rows' => static fn ($query) => $query
                    ->orderByDesc('row_number')
                    ->limit(100),
            ])
            ->findOrFail($marketplaceImport);
        Gate::authorize('view', $import);

        return new MarketplaceImportResource($import);
    }
}
