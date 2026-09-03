<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Monitoring\SavedSearchMatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SavedSearchResource;
use App\Models\SavedSearch;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;

class ShowSavedSearchController extends Controller
{
    public function __invoke(
        SavedSearch $savedSearch,
        OrganizationContext $context,
    ): SavedSearchResource {
        $record = SavedSearch::query()
            ->forOrganization($context->organization())
            ->with([
                'owner:id,name',
                'currentVersion.category',
                'currentVersion.brand',
                'currentVersion.productModel',
                'versions.category',
                'versions.brand',
                'versions.productModel',
            ])
            ->withCount(['versions', 'matches'])
            ->withCount([
                'matches as matched_count' => fn ($query) => $query->where(
                    'status',
                    SavedSearchMatchStatus::Matched,
                ),
            ])
            ->findOrFail($savedSearch->getKey());
        Gate::authorize('view', $record);

        return new SavedSearchResource($record);
    }
}
