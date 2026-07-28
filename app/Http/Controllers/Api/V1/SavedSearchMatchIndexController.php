<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Monitoring\IndexSavedSearchMatchRequest;
use App\Http\Resources\V1\SavedSearchMatchResource;
use App\Models\SavedSearch;
use App\Models\SavedSearchMatch;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SavedSearchMatchIndexController extends Controller
{
    public function __invoke(
        IndexSavedSearchMatchRequest $request,
        SavedSearch $savedSearch,
        OrganizationContext $context,
    ): AnonymousResourceCollection {
        $record = SavedSearch::query()
            ->forOrganization($context->organization())
            ->findOrFail($savedSearch->getKey());
        Gate::authorize('view', $record);
        $validated = $request->validated();
        $query = SavedSearchMatch::query()
            ->forOrganization($context->organization())
            ->where('saved_search_id', $record->getKey())
            ->with('listing')
            ->when(
                $validated['status'] ?? null,
                fn ($query, $status) => $query->where('status', $status),
            )
            ->orderByDesc('evaluated_at')
            ->orderByDesc('id');

        return SavedSearchMatchResource::collection(
            $query->cursorPaginate((int) ($validated['per_page'] ?? 20)),
        );
    }
}
