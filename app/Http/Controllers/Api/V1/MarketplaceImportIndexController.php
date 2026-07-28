<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MarketplaceImports\IndexMarketplaceImportRequest;
use App\Http\Resources\V1\MarketplaceImportResource;
use App\Models\MarketplaceImport;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class MarketplaceImportIndexController extends Controller
{
    public function __invoke(
        IndexMarketplaceImportRequest $request,
        OrganizationContext $context,
    ): AnonymousResourceCollection {
        $organization = $context->organization();
        Gate::authorize('viewAny', [MarketplaceImport::class, $organization]);
        $validated = $request->validated();
        $query = MarketplaceImport::query()
            ->forOrganization($organization)
            ->with(['marketplaceSource', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return MarketplaceImportResource::collection(
            $query->cursorPaginate((int) ($validated['per_page'] ?? 20)),
        );
    }
}
