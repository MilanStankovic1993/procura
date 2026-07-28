<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Monitoring\UpdateSavedSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Monitoring\ArchiveSavedSearchRequest;
use App\Http\Resources\V1\SavedSearchResource;
use App\Models\SavedSearch;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ArchiveSavedSearchController extends Controller
{
    public function __invoke(
        ArchiveSavedSearchRequest $request,
        SavedSearch $savedSearch,
        OrganizationContext $context,
        UpdateSavedSearch $action,
    ): JsonResponse {
        $record = SavedSearch::query()
            ->forOrganization($context->organization())
            ->findOrFail($savedSearch->getKey());
        Gate::authorize('update', $record);
        $validated = $request->validated();
        $result = $action->update(
            $record,
            $request->user(),
            [],
            $validated['expected_current_version_id'],
            $validated['reason_code'],
            $validated['idempotency_key'],
            archive: true,
        );
        $result->savedSearch->load([
            'owner:id,name',
            'currentVersion.category',
            'currentVersion.brand',
            'currentVersion.productModel',
        ])
            ->loadCount(['versions', 'matches']);

        return (new SavedSearchResource($result->savedSearch))
            ->additional([
                'meta' => [
                    'created' => $result->created,
                    'version_id' => $result->version->getKey(),
                ],
            ])
            ->response();
    }
}
