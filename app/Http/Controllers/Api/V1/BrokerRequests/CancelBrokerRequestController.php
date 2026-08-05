<?php

namespace App\Http\Controllers\Api\V1\BrokerRequests;

use App\Actions\BrokerRequests\TransitionBrokerRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BrokerRequests\TransitionBrokerRequestRequest;
use App\Http\Resources\V1\BrokerRequestResource;
use App\Models\BrokerRequest;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;

class CancelBrokerRequestController extends Controller
{
    public function __invoke(
        TransitionBrokerRequestRequest $request,
        string $brokerRequest,
        OrganizationContext $context,
        TransitionBrokerRequest $transition,
    ): BrokerRequestResource {
        $record = BrokerRequest::query()
            ->forOrganization($context->organization())
            ->findOrFail($brokerRequest);
        Gate::authorize('update', $record);
        $result = $transition->cancel(
            $record,
            $request->user(),
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
