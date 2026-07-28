<?php

use App\Actions\Markets\SyncMarketReferenceData;
use App\Actions\Privacy\TransitionPrivacyRequest;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationType;
use App\Enums\Privacy\PrivacyRequestStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditEvent;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
});

function privacyRequestPayload(array $overrides = []): array
{
    return [
        'type' => 'data_export',
        'residence_country_code' => 'RS',
        'reason' => 'I need a portable copy of my Procura account data.',
        'privacy_notice_confirmed' => true,
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

test('verified subjects create bounded idempotent privacy requests without an organization context', function () {
    $user = User::factory()->create();
    $payload = privacyRequestPayload();

    $this->actingAs($user)
        ->postJson(route('api.v1.me.privacy-requests.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('meta.created', true)
        ->assertJsonPath('data.type', 'data_export')
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.residence_country_code', 'RS')
        ->assertJsonPath('data.blocking_reason_codes', [])
        ->assertJsonPath('data.event_sequence', 1)
        ->assertJsonPath(
            'data.current_event.reason_code',
            'privacy_request_submitted',
        )
        ->assertJsonCount(1, 'data.events')
        ->assertJsonMissingPath('data.requester_email_hash')
        ->assertJsonMissingPath('data.payload_hash');

    $this->actingAs($user)
        ->postJson(route('api.v1.me.privacy-requests.store'), $payload)
        ->assertOk()
        ->assertJsonPath('meta.created', false);

    expect(PrivacyRequest::query()->count())->toBe(1)
        ->and(PrivacyRequestEvent::query()->count())->toBe(1)
        ->and(PlatformAuditEvent::query()
            ->where('action', 'privacy_request.created')
            ->count())->toBe(1);

    $this->actingAs($user)
        ->getJson(route('api.v1.me.privacy-requests.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.events')
        ->assertJsonPath('data.0.can_cancel', true);

    $request = PrivacyRequest::query()->firstOrFail();
    $event = PrivacyRequestEvent::query()->firstOrFail();
    expect(fn () => $request->delete())
        ->toThrow(LogicException::class, 'cannot be deleted')
        ->and(fn () => $event->update(['note' => 'changed']))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $event->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');
});

test('privacy intake enforces validation verification and one active request per type', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(
            route('api.v1.me.privacy-requests.store'),
            privacyRequestPayload(),
        )
        ->assertCreated();

    $this->actingAs($user)
        ->postJson(
            route('api.v1.me.privacy-requests.store'),
            privacyRequestPayload(),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    $this->actingAs($user)
        ->postJson(
            route('api.v1.me.privacy-requests.store'),
            privacyRequestPayload([
                'type' => 'account_deletion',
                'reason' => 'I want to close my Procura account permanently.',
            ]),
        )
        ->assertCreated();

    $invalid = privacyRequestPayload([
        'type' => 'unsupported',
        'residence_country_code' => 'ZZ',
        'reason' => 'short',
        'privacy_notice_confirmed' => false,
        'idempotency_key' => 'not-a-uuid',
    ]);
    $this->actingAs($user)
        ->postJson(route('api.v1.me.privacy-requests.store'), $invalid)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'type',
            'residence_country_code',
            'reason',
            'privacy_notice_confirmed',
            'idempotency_key',
        ]);

    $this->actingAs(User::factory()->unverified()->create())
        ->postJson(
            route('api.v1.me.privacy-requests.store'),
            privacyRequestPayload(),
        )
        ->assertForbidden();
});

test('guests cannot access the personal privacy-request workflow', function () {
    $this->postJson(
        route('api.v1.me.privacy-requests.store'),
        privacyRequestPayload(),
    )->assertUnauthorized();
});

test('privacy endpoints have an independent per-subject rate limit', function () {
    $user = User::factory()->create();
    $route = route('api.v1.me.privacy-requests.index');

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($user)
            ->getJson($route)
            ->assertOk();
    }

    $this->actingAs($user)
        ->getJson($route)
        ->assertTooManyRequests();
});

test('privacy intake fails closed for invalid production workflow configuration', function () {
    config(['privacy.response_target_days' => 0]);

    $this->actingAs(User::factory()->create())
        ->postJson(
            route('api.v1.me.privacy-requests.store'),
            privacyRequestPayload(),
        )
        ->assertServerError();

    expect(PrivacyRequest::query()->exists())->toBeFalse()
        ->and(PrivacyRequestEvent::query()->exists())->toBeFalse();
});

test('account deletion snapshots operational blockers and a deterministic response target', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    $user->forceFill(['is_super_admin' => true])->save();
    $organization = Organization::factory()->create([
        'type' => OrganizationType::Business,
        'personal_user_id' => null,
    ]);
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $user,
        'role' => OrganizationRole::Owner,
    ]);
    DB::table('subscriptions')->insert([
        'organization_id' => $organization->getKey(),
        'type' => 'default',
        'stripe_id' => 'sub_privacy_blocker',
        'stripe_status' => 'active',
        'stripe_price' => 'price_privacy',
        'quantity' => 1,
        'trial_ends_at' => null,
        'ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->postJson(
            route('api.v1.me.privacy-requests.store'),
            privacyRequestPayload([
                'type' => 'account_deletion',
                'reason' => 'Please review and close my account safely.',
            ]),
        )
        ->assertCreated()
        ->assertJsonPath(
            'data.response_target_at',
            now()->addDays(30)->toIso8601String(),
        );

    expect($response->json('data.blocking_reason_codes'))->toBe([
        'active_subscription_resolution_required',
        'business_ownership_transfer_required',
        'retention_review_required',
        'super_admin_reassignment_required',
    ]);
});

test('subjects cancel with optimistic concurrency idempotency and strict ownership isolation', function () {
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    $id = $this->actingAs($user)
        ->postJson(
            route('api.v1.me.privacy-requests.store'),
            privacyRequestPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $eventId = PrivacyRequest::query()->findOrFail($id)->current_event_id;
    $route = route('api.v1.me.privacy-requests.cancel', $id);
    $idempotencyKey = (string) Str::uuid();
    $payload = [
        'expected_current_event_id' => $eventId,
        'idempotency_key' => $idempotencyKey,
        'note' => 'I no longer need this export.',
    ];

    $this->actingAs($outsider)
        ->postJson($route, $payload)
        ->assertNotFound();
    $this->actingAs($user)
        ->postJson($route, [
            ...$payload,
            'expected_current_event_id' => (string) Str::ulid(),
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'privacy_request_stale_state');

    $this->actingAs($user)
        ->postJson($route, $payload)
        ->assertOk()
        ->assertJsonPath('meta.event_created', true)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.can_cancel', false)
        ->assertJsonCount(2, 'data.events');
    $this->actingAs($user)
        ->postJson($route, $payload)
        ->assertOk()
        ->assertJsonPath('meta.event_created', false);

    expect(PrivacyRequestEvent::query()->count())->toBe(2)
        ->and(PrivacyRequest::query()->firstOrFail()->active_key)->toBeNull()
        ->and(PlatformAuditEvent::query()
            ->where('action', 'privacy_request.cancelled')
            ->count())->toBe(1);
});

test('verified super administrators append evidence-bound operational transitions', function () {
    $subject = User::factory()->create();
    $operator = User::factory()->create();
    $operator->forceFill(['is_super_admin' => true])->save();
    $requestId = $this->actingAs($subject)
        ->postJson(
            route('api.v1.me.privacy-requests.store'),
            privacyRequestPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $privacyRequest = PrivacyRequest::query()->findOrFail($requestId);
    $ordinaryOperator = User::factory()->create();

    expect(fn () => app(TransitionPrivacyRequest::class)->transition(
        request: $privacyRequest,
        operator: $ordinaryOperator,
        nextStatus: PrivacyRequestStatus::InReview,
        expectedCurrentEventId: $privacyRequest->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'review_started',
        note: 'Privacy operations review has started.',
    ))->toThrow(AuthorizationException::class);

    $reviewKey = (string) Str::uuid();
    $reviewOptions = [
        '--actor-email' => $operator->email,
        '--expected-event' => $privacyRequest->current_event_id,
        '--idempotency' => $reviewKey,
        '--reason-code' => 'review_started',
        '--note' => 'Privacy operations review has started.',
    ];
    $this->artisan('privacy-requests:transition', [
        'request' => $privacyRequest->getKey(),
        'status' => 'in_review',
        ...$reviewOptions,
    ])->assertSuccessful();
    $this->artisan('privacy-requests:transition', [
        'request' => $privacyRequest->getKey(),
        'status' => 'in_review',
        ...$reviewOptions,
    ])->assertSuccessful();

    $privacyRequest->refresh();
    $this->artisan('privacy-requests:transition', [
        'request' => $privacyRequest->getKey(),
        'status' => 'approved',
        '--actor-email' => $operator->email,
        '--expected-event' => $privacyRequest->current_event_id,
        '--idempotency' => (string) Str::uuid(),
        '--reason-code' => 'export_approved',
        '--note' => 'The export scope and identity were reviewed.',
    ])->assertFailed();

    $this->artisan('privacy-requests:transition', [
        'request' => $privacyRequest->getKey(),
        'status' => 'approved',
        '--actor-email' => $operator->email,
        '--expected-event' => $privacyRequest->current_event_id,
        '--idempotency' => (string) Str::uuid(),
        '--reason-code' => 'export_approved',
        '--note' => 'The export scope and identity were reviewed.',
        '--evidence' => 'privacy-case-2026-0001',
    ])->assertSuccessful();

    $privacyRequest->refresh();
    $this->artisan('privacy-requests:transition', [
        'request' => $privacyRequest->getKey(),
        'status' => 'fulfilled',
        '--actor-email' => $operator->email,
        '--expected-event' => $privacyRequest->current_event_id,
        '--idempotency' => (string) Str::uuid(),
        '--reason-code' => 'export_delivered',
        '--note' => 'The approved export was delivered through the reviewed channel.',
        '--evidence' => 'privacy-delivery-2026-0001',
    ])->assertSuccessful();

    expect($privacyRequest->refresh()->status)
        ->toBe(PrivacyRequestStatus::Fulfilled)
        ->and($privacyRequest->active_key)->toBeNull()
        ->and($privacyRequest->resolved_at)->not->toBeNull()
        ->and(PrivacyRequestEvent::query()->count())->toBe(4)
        ->and(PlatformAuditEvent::query()
            ->where('action', 'privacy_request.transitioned')
            ->count())->toBe(3);
});
