<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MarketplaceImports\CreateMarketplaceImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MarketplaceImports\StoreMarketplaceImportRequest;
use App\Http\Resources\V1\MarketplaceImportResource;
use App\Models\MarketplaceImport;
use App\Models\MarketplaceSource;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class StoreMarketplaceImportController extends Controller
{
    public function __invoke(
        StoreMarketplaceImportRequest $request,
        OrganizationContext $context,
        CreateMarketplaceImport $imports,
    ): JsonResponse {
        abort_unless(
            config('marketplace_connectors.csv_import_enabled'),
            404,
        );
        $organization = $context->organization();
        Gate::authorize('create', [MarketplaceImport::class, $organization]);
        $validated = $request->validated();
        $source = MarketplaceSource::query()
            ->where('key', $validated['marketplace_source_key'])
            ->where('connector_type', 'csv')
            ->where('compliance_status', 'approved')
            ->where('active', true)
            ->firstOrFail();

        $import = $imports->create(
            $organization,
            $request->user(),
            $source,
            $request->file('file'),
            $validated['idempotency_key'],
            $validated['delimiter'],
            $validated['default_target_country_code'] ?? null,
        )->load(['marketplaceSource', 'createdBy:id,name']);

        return (new MarketplaceImportResource($import))
            ->response()
            ->setStatusCode(202);
    }
}
