<?php

namespace App\Http\Controllers\Api\V1\BrokerRequests;

use App\Actions\BrokerRequests\TransitionBrokerRequest;
use App\Enums\Validation\ApplicationValidationCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BrokerRequests\TransitionBrokerRequestRequest;
use App\Http\Resources\V1\BrokerRequestResource;
use App\Models\BrokerRequest;
use App\Subscriptions\UsageLimitExceeded;
use App\Support\Validation\ApplicationValidation;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SubmitBrokerRequestController extends Controller
{
    public function __invoke(
        TransitionBrokerRequestRequest $request,
        string $brokerRequest,
        OrganizationContext $context,
        TransitionBrokerRequest $transition,
    ): JsonResponse {
        $record = BrokerRequest::query()
            ->forOrganization($context->organization())
            ->findOrFail($brokerRequest);
        Gate::authorize('update', $record);

        try {
            $result = $transition->submit(
                $record,
                $request->user(),
                $request->validated('expected_current_event_id'),
                $request->validated('idempotency_key'),
            );
        } catch (UsageLimitExceeded $exception) {
            ApplicationValidation::fail(
                'broker_request',
                ApplicationValidationCode::BrokerRequestQuotaExceeded,
                ['limit' => $exception->limit],
            );
        }

        return (new BrokerRequestResource(
            $result->brokerRequest->load([
                'productCategory',
                'requester:id,name',
                'events.actor:id,name',
            ]),
        ))->response()->setStatusCode($result->created ? 202 : 200);
    }
}
