<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Actions\Privacy\CancelPrivacyRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Privacy\CancelPrivacyRequestRequest;
use App\Http\Resources\V1\PrivacyRequestResource;
use App\Models\PrivacyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CancelPrivacyRequestController extends Controller
{
    public function __invoke(
        CancelPrivacyRequestRequest $request,
        string $privacyRequest,
        CancelPrivacyRequest $action,
    ): JsonResponse {
        $record = PrivacyRequest::query()
            ->where('subject_user_id', $request->user()->getKey())
            ->findOrFail($privacyRequest);
        Gate::authorize('cancel', $record);
        $validated = $request->validated();
        $result = $action->cancel(
            request: $record,
            subject: $request->user(),
            expectedCurrentEventId: $validated[
                'expected_current_event_id'
            ],
            idempotencyKey: $validated['idempotency_key'],
            note: $validated['note'] ?? null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
        $result['privacy_request']->load([
            'residenceCountry',
            'currentEvent.actor:id,name',
            'events.actor:id,name',
        ]);

        return (new PrivacyRequestResource($result['privacy_request']))
            ->additional([
                'meta' => [
                    'event_created' => $result['event_created'],
                ],
            ])
            ->response();
    }
}
