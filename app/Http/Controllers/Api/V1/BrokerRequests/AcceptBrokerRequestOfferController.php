<?php

namespace App\Http\Controllers\Api\V1\BrokerRequests;

use App\Actions\BrokerRequests\AcceptBrokerRequestOffer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BrokerRequests\AcceptBrokerRequestOfferRequest;
use App\Http\Resources\V1\BrokerRequestResource;
use App\Models\BrokerRequest;
use App\Models\BrokerRequestOffer;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AcceptBrokerRequestOfferController extends Controller
{
    public function __invoke(
        AcceptBrokerRequestOfferRequest $request,
        string $brokerRequest,
        string $offer,
        OrganizationContext $context,
        AcceptBrokerRequestOffer $action,
    ): JsonResponse {
        $organization = $context->organization();
        $record = BrokerRequest::query()
            ->forOrganization($organization)
            ->findOrFail($brokerRequest);
        Gate::authorize('update', $record);
        $offerRecord = BrokerRequestOffer::query()
            ->forOrganization($organization)
            ->where('broker_request_id', $record->getKey())
            ->findOrFail($offer);
        $result = $action->execute(
            request: $record,
            offer: $offerRecord,
            actor: $request->user(),
            expectedRequestEventId: $request->validated(
                'expected_request_event_id',
            ),
            expectedOfferEventId: $request->validated(
                'expected_offer_event_id',
            ),
            idempotencyKey: $request->validated('idempotency_key'),
        );

        return (new BrokerRequestResource(
            $result->brokerRequest->load([
                'productCategory',
                'requester:id,name',
                'events.actor:id,name',
                'offers.events.actor:id,name',
                'brokerTransaction.events.actor:id,name',
                'brokerTransaction.commission.events.actor:id,name',
            ]),
        ))->response()->setStatusCode($result->created ? 202 : 200);
    }
}
