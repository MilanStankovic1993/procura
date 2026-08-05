<?php

namespace App\Actions\BrokerRequests;

use App\BrokerRequests\BrokerRequestInput;
use App\BrokerRequests\BrokerRequestSnapshot;
use App\BrokerRequests\Data\BrokerRequestWriteResult;
use App\Enums\BrokerRequests\BrokerRequestEventType;
use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\BrokerRequest;
use App\Models\BrokerRequestEvent;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class UpdateBrokerRequestDraft
{
    private const MAX_EVENT_HISTORY = 1000;

    public function __construct(
        private readonly BrokerRequestInput $input,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(
        BrokerRequest $request,
        User $actor,
        array $input,
        string $expectedCurrentEventId,
        string $idempotencyKey,
    ): BrokerRequestWriteResult {
        $this->assertEnabled();

        if (
            ! Str::isUuid($idempotencyKey)
            || ! Str::isUlid($expectedCurrentEventId)
        ) {
            throw new InvalidArgumentException(
                'The broker-request event head or idempotency key is invalid.',
            );
        }

        $normalized = $this->input->normalizeAndValidate($input);
        $snapshot = BrokerRequestSnapshot::fromInput(
            $normalized,
            BrokerRequestStatus::Draft,
        );
        $requestHash = BrokerRequestInput::hash($snapshot);
        $payloadHash = BrokerRequestInput::hash([
            'operation' => BrokerRequestEventType::Updated->value,
            'broker_request_id' => $request->getKey(),
            'expected_current_event_id' => $expectedCurrentEventId,
            'request' => $snapshot,
        ]);

        return DB::transaction(function () use (
            $request,
            $actor,
            $normalized,
            $snapshot,
            $requestHash,
            $payloadHash,
            $expectedCurrentEventId,
            $idempotencyKey,
        ): BrokerRequestWriteResult {
            $locked = BrokerRequest::query()
                ->forOrganization($request->organization_id)
                ->lockForUpdate()
                ->findOrFail($request->getKey());
            $existing = BrokerRequestEvent::query()
                ->forOrganization($locked->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertReplay($existing, $payloadHash);

                return new BrokerRequestWriteResult(
                    brokerRequest: $existing->brokerRequest()->firstOrFail(),
                    event: $existing,
                    created: false,
                );
            }

            if ($locked->current_event_id !== $expectedCurrentEventId) {
                ApplicationValidation::fail(
                    'expected_current_event_id',
                    ApplicationValidationCode::BrokerRequestStale,
                );
            }

            if ($locked->status !== BrokerRequestStatus::Draft) {
                ApplicationValidation::fail(
                    'broker_request',
                    ApplicationValidationCode::BrokerRequestImmutable,
                );
            }

            if ($locked->event_sequence >= self::MAX_EVENT_HISTORY) {
                ApplicationValidation::fail(
                    'broker_request',
                    ApplicationValidationCode::BrokerRequestHistoryLimit,
                );
            }

            $currentEvent = $locked->currentEvent()->firstOrFail();

            if (hash_equals($currentEvent->request_hash, $requestHash)) {
                return new BrokerRequestWriteResult(
                    brokerRequest: $locked,
                    event: $currentEvent,
                    created: false,
                );
            }

            $sequence = $locked->event_sequence + 1;
            $event = $locked->events()->create([
                'organization_id' => $locked->organization_id,
                'previous_event_id' => $locked->current_event_id,
                'actor_user_id' => $actor->getKey(),
                'sequence' => $sequence,
                'event_type' => BrokerRequestEventType::Updated,
                'from_status' => BrokerRequestStatus::Draft,
                'to_status' => BrokerRequestStatus::Draft,
                'reason_code' => 'request_updated',
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'request_hash' => $requestHash,
                'request_snapshot' => $snapshot,
                'occurred_at' => now(),
            ]);
            $locked->forceFill([
                ...$normalized,
                'current_event_id' => $event->getKey(),
                'event_sequence' => $sequence,
            ])->save();

            return new BrokerRequestWriteResult(
                brokerRequest: $locked,
                event: $event,
                created: true,
            );
        }, attempts: 3);
    }

    private function assertEnabled(): void
    {
        if (! config('broker.requests_enabled', true)) {
            ApplicationValidation::fail(
                'broker_request',
                ApplicationValidationCode::BrokerRequestsDisabled,
            );
        }
    }

    private function assertReplay(
        BrokerRequestEvent $existing,
        string $payloadHash,
    ): void {
        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            ApplicationValidation::fail(
                'idempotency_key',
                ApplicationValidationCode::BrokerRequestIdempotencyConflict,
            );
        }
    }
}
