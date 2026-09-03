<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProducts\IndexOwnedProductRequest;
use App\Http\Resources\V1\OwnedProductResource;
use App\Models\OwnedProduct;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OwnedProductIndexController extends Controller
{
    public function __invoke(
        IndexOwnedProductRequest $request,
        OrganizationContext $context,
    ): AnonymousResourceCollection {
        $organization = $context->organization();
        Gate::authorize('viewAny', [OwnedProduct::class, $organization]);
        $validated = $request->validated();

        $query = OwnedProduct::query()
            ->forOrganization($organization)
            ->with(['category', 'targetCountries'])
            ->withCount(['images', 'snapshots', 'assessments'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($validated['q'])) {
            $search = '%'.addcslashes(trim($validated['q']), '%_\\').'%';
            $query->where(static function ($query) use ($search): void {
                $query
                    ->where('brand_name', 'like', $search)
                    ->orWhere('model_name', 'like', $search)
                    ->orWhere('notes', 'like', $search);
            });
        }

        foreach (['status', 'target_continent_code'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        return OwnedProductResource::collection(
            $query->cursorPaginate((int) ($validated['per_page'] ?? 20)),
        );
    }
}
