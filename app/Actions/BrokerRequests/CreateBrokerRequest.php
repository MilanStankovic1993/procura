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
use App\Models\Organization;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateBrokerRequest
{
    public function __construct(
        private readonly BrokerRequestInput $input,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(
        Organization $organization,
        User $actor,
        array $input,
        string $idempotencyKey,
    ): BrokerRequestWriteResult {
        $this->assertEnabled();

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException(
                'The broker-request idempotency key must be a UUID.',
            );
        }

        $normalized = $this->input->normalizeAndValidate($input);
        $snapshot = BrokerRequestSnapshot::fromInput(
            $normalized,
            BrokerRequestStatus::Draft,
        );
        $requestHash = BrokerRequestInput::hash($snapshot);
        $payloadHash = BrokerRequestInput::hash([
            'operation' => BrokerRequestEventType::Created->value,
            'request' => $snapshot,
        ]);

        return DB::transaction(function () use (
            $organization,
            $actor,
            $normalized,
            $snapshot,
            $requestHash,
            $payloadHash,
            $idempotencyKey,
        ): BrokerRequestWriteResult {
            $existing = BrokerRequestEvent::query()
                ->forOrganization($organization)
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

            Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $request = BrokerRequest::query()->create([
                ...$normalized,
                'organization_id' => $organization->getKey(),
                'requester_user_id' => $actor->getKey(),
                'status' => BrokerRequestStatus::Draft,
                'event_sequence' => 0,
            ]);
            $event = $request->events()->create([
                'organization_id' => $organization->getKey(),
                'actor_user_id' => $actor->getKey(),
                'sequence' => 1,
                'event_type' => BrokerRequestEventType::Created,
                'from_status' => null,
                'to_status' => BrokerRequestStatus::Draft,
                'reason_code' => 'request_created',
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'request_hash' => $requestHash,
                'request_snapshot' => $snapshot,
                'occurred_at' => now(),
            ]);
            $request->forceFill([
                'current_event_id' => $event->getKey(),
                'event_sequence' => 1,
            ])->save();

            return new BrokerRequestWriteResult(
                brokerRequest: $request,
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
