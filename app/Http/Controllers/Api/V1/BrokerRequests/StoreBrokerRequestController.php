<?php

namespace App\Http\Controllers\Api\V1\BrokerRequests;

use App\Actions\BrokerRequests\CreateBrokerRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BrokerRequests\StoreBrokerRequestRequest;
use App\Http\Resources\V1\BrokerRequestResource;
use App\Models\BrokerRequest;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreBrokerRequestController extends Controller
{
    public function __invoke(
        StoreBrokerRequestRequest $request,
        OrganizationContext $context,
        CreateBrokerRequest $creator,
    ): JsonResponse {
        $organization = $context->organization();
        Gate::authorize('create', [BrokerRequest::class, $organization]);
        $result = $creator->create(
            $organization,
            $request->user(),
            $request->safe()->except('idempotency_key'),
            $request->validated('idempotency_key'),
        );

        return (new BrokerRequestResource(
            $result->brokerRequest->load([
                'productCategory',
                'requester:id,name',
                'events.actor:id,name',
            ]),
        ))->response()->setStatusCode($result->created ? 201 : 200);
    }
}
