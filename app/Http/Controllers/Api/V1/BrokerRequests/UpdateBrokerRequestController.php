<?php

namespace App\Http\Controllers\Api\V1\BrokerRequests;

use App\Actions\BrokerRequests\UpdateBrokerRequestDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BrokerRequests\UpdateBrokerRequestRequest;
use App\Http\Resources\V1\BrokerRequestResource;
use App\Models\BrokerRequest;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;

class UpdateBrokerRequestController extends Controller
{
    public function __invoke(
        UpdateBrokerRequestRequest $request,
        string $brokerRequest,
        OrganizationContext $context,
        UpdateBrokerRequestDraft $updater,
    ): BrokerRequestResource {
        $record = BrokerRequest::query()
            ->forOrganization($context->organization())
            ->findOrFail($brokerRequest);
        Gate::authorize('update', $record);
        $result = $updater->update(
            $record,
            $request->user(),
            $request->safe()->except([
                'expected_current_event_id',
                'idempotency_key',
            ]),
            $request->validated('expected_current_event_id'),
            $request->validated('idempotency_key'),
        );

        return new BrokerRequestResource(
            $result->brokerRequest->load([
                'productCategory',
                'requester:id,name',
                'events.actor:id,name',
            ]),
        );
    }
}
