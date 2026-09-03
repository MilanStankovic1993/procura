<?php

namespace App\Http\Controllers\Api\V1\Listings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Listings\IndexListingRequest;
use App\Http\Resources\V1\ListingResource;
use App\Models\Listing;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ListingIndexController extends Controller
{
    public function __invoke(
        IndexListingRequest $request,
        OrganizationContext $context,
    ): AnonymousResourceCollection {
        $organization = $context->organization();
        Gate::authorize('viewAny', [Listing::class, $organization]);
        $validated = $request->validated();

        $query = Listing::query()
            ->forOrganization($organization)
            ->with(['marketplaceSource', 'currency'])
            ->withCount(['images', 'snapshots'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($validated['q'])) {
            $search = '%'.addcslashes(trim($validated['q']), '%_\\').'%';
            $query->where(static function ($query) use ($search): void {
                $query
                    ->where('title', 'like', $search)
                    ->orWhere('marketplace_name', 'like', $search)
                    ->orWhere('external_id', 'like', $search);
            });
        }

        foreach (['status', 'source_country_code', 'target_country_code'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        return ListingResource::collection(
            $query->cursorPaginate((int) ($validated['per_page'] ?? 20)),
        );
    }
}
