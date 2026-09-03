<?php

namespace App\Http\Controllers\Api\V1\BrokerRequests;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BrokerRequestResource;
use App\Models\BrokerRequest;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;

class ShowBrokerRequestController extends Controller
{
    public function __invoke(
        string $brokerRequest,
        OrganizationContext $context,
    ): BrokerRequestResource {
        $record = BrokerRequest::query()
            ->forOrganization($context->organization())
            ->with([
                'productCategory',
                'requester:id,name',
                'events.actor:id,name',
                'offers.events.actor:id,name',
                'brokerTransaction.events.actor:id,name',
                'brokerTransaction.commission.events.actor:id,name',
                'brokerTransaction.reports',
                'brokerTransaction.paymentCases.events.actor:id,name',
            ])
            ->findOrFail($brokerRequest);
        Gate::authorize('view', $record);

        return new BrokerRequestResource($record);
    }
}
