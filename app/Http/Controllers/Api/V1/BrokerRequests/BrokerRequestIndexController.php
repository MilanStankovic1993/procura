<?php

namespace App\Http\Controllers\Api\V1\BrokerRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BrokerRequests\IndexBrokerRequestRequest;
use App\Http\Resources\V1\BrokerRequestResource;
use App\Models\BrokerRequest;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BrokerRequestIndexController extends Controller
{
    public function __invoke(
        IndexBrokerRequestRequest $request,
        OrganizationContext $context,
    ): AnonymousResourceCollection {
        $organization = $context->organization();
        Gate::authorize('viewAny', [BrokerRequest::class, $organization]);
        $validated = $request->validated();

        $query = BrokerRequest::query()
            ->forOrganization($organization)
            ->with(['productCategory', 'requester:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($validated['q'])) {
            $search = '%'.addcslashes(trim($validated['q']), '%_\\').'%';
            $query->where(static function ($query) use ($search): void {
                $query
                    ->where('title', 'like', $search)
                    ->orWhere('product_description', 'like', $search)
                    ->orWhere('brand_preference', 'like', $search)
                    ->orWhere('model_preference', 'like', $search);
            });
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return BrokerRequestResource::collection(
            $query->cursorPaginate((int) ($validated['per_page'] ?? 20)),
        );
    }
}
