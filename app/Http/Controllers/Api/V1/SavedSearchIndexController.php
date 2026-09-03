<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Monitoring\SavedSearchMatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Monitoring\IndexSavedSearchRequest;
use App\Http\Resources\V1\SavedSearchResource;
use App\Models\SavedSearch;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SavedSearchIndexController extends Controller
{
    public function __invoke(
        IndexSavedSearchRequest $request,
        OrganizationContext $context,
    ): AnonymousResourceCollection {
        $organization = $context->organization();
        Gate::authorize('viewAny', [SavedSearch::class, $organization]);
        $validated = $request->validated();
        $state = $validated['state'] ?? 'active';
        $query = SavedSearch::query()
            ->forOrganization($organization)
            ->with([
                'owner:id,name',
                'currentVersion.category',
                'currentVersion.brand',
                'currentVersion.productModel',
            ])
            ->withCount(['versions', 'matches'])
            ->withCount([
                'matches as matched_count' => fn ($query) => $query->where(
                    'status',
                    SavedSearchMatchStatus::Matched,
                ),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        match ($state) {
            'active' => $query
                ->whereNull('archived_at')
                ->where('active', true),
            'paused' => $query
                ->whereNull('archived_at')
                ->where('active', false),
            'archived' => $query->whereNotNull('archived_at'),
            default => null,
        };

        if (isset($validated['q'])) {
            $search = '%'.addcslashes(trim($validated['q']), '%_\\').'%';
            $query->where('title', 'like', $search);
        }

        return SavedSearchResource::collection(
            $query->cursorPaginate((int) ($validated['per_page'] ?? 20)),
        );
    }
}
