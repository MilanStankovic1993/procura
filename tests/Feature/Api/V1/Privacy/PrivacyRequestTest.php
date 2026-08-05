<?php

use App\Actions\Markets\SyncMarketReferenceData;
use App\Actions\Organizations\CreatePersonalOrganization;
use App\Actions\Privacy\CreatePrivacyRequest;
use App\Actions\Privacy\TransitionPrivacyRequest;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationType;
use App\Enums\Privacy\PrivacyRequestStatus;
use App\Enums\Privacy\PrivacyRequestType;
use App\Http\Resources\V1\PrivacyRequestFulfillmentResource;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditEvent;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\PrivacyRequestFulfillment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

function approvedAccountDeletionRequest(
    User $subject,
    User $operator,
): PrivacyRequest {
    $request = app(CreatePrivacyRequest::class)->create(
        subject: $subject,
        attributes: [
            'type' => 'account_deletion',
            'residence_country_code' => 'RS',
            'reason' => 'Please erase this account after the required review.',
            'idempotency_key' => (string) Str::uuid(),
        ],
    )['privacy_request'];

    app(TransitionPrivacyRequest::class)->transition(
        request: $request,
        operator: $operator,
        nextStatus: PrivacyRequestStatus::InReview,
        expectedCurrentEventId: $request->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'erasure_review_started',
        note: 'The controlled account-erasure review has started.',
    );
    $request->refresh();
    app(TransitionPrivacyRequest::class)->transition(
        request: $request,
        operator: $operator,
        nextStatus: PrivacyRequestStatus::Approved,
        expectedCurrentEventId: $request->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'account_erasure_approved',
        note: 'Identity, scope, retention, and erasure controls were reviewed.',
        evidenceReference: 'privacy-erasure-approval-2026-0001',
    );

    return $request->fresh();
}

/**
 * @return array<string, mixed>
 */
function accountErasureOptions(
    PrivacyRequest $request,
    User $operator,
    array $overrides = [],
): array {
    return [
        '--actor-email' => $operator->email,
        '--expected-event' => $request->current_event_id,
        '--idempotency' => (string) Str::uuid(),
        '--inventory-version' => 'privacy-erasure-inventory:v4',
        '--identity-evidence' => 'privacy-identity-2026-0003',
        '--erasure-evidence' => 'privacy-erasure-run-2026-0003',
        '--storage-evidence' => 'privacy-storage-clearance-2026-0003',
        '--processor-evidence' => 'privacy-processor-clearance-2026-0003',
        '--completion-evidence' => 'privacy-erasure-receipt-2026-0003',
        '--clearance' => [
            'retention_review_required=privacy-retention-clearance-2026-0003',
        ],
        '--backup-purge-due-at' => now()->addDays(30)->toIso8601String(),
        '--note' => 'The approved account erasure was completed under the reviewed runbook.',
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
        'ends_at' => now()->addDay(),
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
    ])->assertFailed();

    config(['privacy.fulfillment.enabled' => true]);
    $completionKey = (string) Str::uuid();
    $completionOptions = [
        '--actor-email' => $operator->email,
        '--expected-event' => $privacyRequest->current_event_id,
        '--idempotency' => $completionKey,
        '--inventory-version' => 'privacy-data-inventory:v4',
        '--identity-evidence' => 'privacy-identity-2026-0001',
        '--artifact-reference' => 'private-vault:export-2026-0001',
        '--artifact-sha256' => str_repeat('a', 64),
        '--artifact-size-bytes' => 4096,
        '--artifact-expires-at' => now()->addDays(7)->toIso8601String(),
        '--delivery-evidence' => 'privacy-delivery-2026-0001',
        '--note' => 'The approved export was delivered through the reviewed channel.',
    ];
    $this->artisan('privacy-requests:complete-export', [
        'request' => $privacyRequest->getKey(),
        ...$completionOptions,
    ])->assertSuccessful();
    $this->artisan('privacy-requests:complete-export', [
        'request' => $privacyRequest->getKey(),
        ...$completionOptions,
        '--artifact-sha256' => str_repeat('f', 64),
    ])->assertFailed();
    config([
        'privacy.fulfillment.enabled' => false,
        'privacy.fulfillment.execution_version' => 'privacy-fulfillment:v2',
        'privacy.fulfillment.data_inventory_version' => 'privacy-data-inventory:v5',
    ]);
    $this->travel(8)->days();
    $this->artisan('privacy-requests:complete-export', [
        'request' => $privacyRequest->getKey(),
        ...$completionOptions,
    ])->assertSuccessful();

    $fulfillment = PrivacyRequestFulfillment::query()->firstOrFail();
    expect($privacyRequest->refresh()->status)
        ->toBe(PrivacyRequestStatus::Fulfilled)
        ->and($privacyRequest->active_key)->toBeNull()
        ->and($privacyRequest->resolved_at)->not->toBeNull()
        ->and(PrivacyRequestEvent::query()->count())->toBe(4)
        ->and(PrivacyRequestFulfillment::query()->count())->toBe(1)
        ->and($fulfillment->artifact_sha256)->toBe(str_repeat('a', 64))
        ->and(fn () => $fulfillment->update([
            'artifact_size_bytes' => 8192,
        ]))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $fulfillment->delete())
        ->toThrow(LogicException::class, 'cannot be deleted')
        ->and(PlatformAuditEvent::query()
            ->where('action', 'privacy_request.transitioned')
            ->count())->toBe(2)
        ->and(PlatformAuditEvent::query()
            ->where('action', 'privacy_request.export_fulfilled')
            ->count())->toBe(1);

    $this->actingAs($subject)
        ->getJson(route('api.v1.me.privacy-requests.index'))
        ->assertOk()
        ->assertJsonPath(
            'data.0.fulfillment.data_inventory_version',
            'privacy-data-inventory:v4',
        )
        ->assertJsonPath('data.0.fulfillment.artifact_size_bytes', 4096)
        ->assertJsonMissingPath('data.0.fulfillment.artifact_reference')
        ->assertJsonMissingPath('data.0.fulfillment.artifact_sha256')
        ->assertJsonMissingPath(
            'data.0.fulfillment.delivery_evidence_reference',
        );
});

test('data-export fulfillment fails closed for gates state type inventory and artifact bounds', function () {
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
    $base = [
        'request' => $privacyRequest->getKey(),
        '--actor-email' => $operator->email,
        '--expected-event' => $privacyRequest->current_event_id,
        '--idempotency' => (string) Str::uuid(),
        '--inventory-version' => 'privacy-data-inventory:v4',
        '--identity-evidence' => 'privacy-identity-2026-0002',
        '--artifact-reference' => 'private-vault:export-2026-0002',
        '--artifact-sha256' => str_repeat('b', 64),
        '--artifact-size-bytes' => 4096,
        '--artifact-expires-at' => now()->addDays(7)->toIso8601String(),
        '--delivery-evidence' => 'privacy-delivery-2026-0002',
        '--note' => 'The reviewed export was delivered through the secure channel.',
    ];

    $this->artisan('privacy-requests:complete-export', $base)
        ->assertFailed();
    expect(PrivacyRequestFulfillment::query()->exists())->toBeFalse();

    config(['privacy.fulfillment.enabled' => true]);
    $this->artisan('privacy-requests:complete-export', $base)
        ->assertFailed();

    app(TransitionPrivacyRequest::class)->transition(
        request: $privacyRequest,
        operator: $operator,
        nextStatus: PrivacyRequestStatus::InReview,
        expectedCurrentEventId: $privacyRequest->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'review_started',
        note: 'Privacy operations review has started.',
    );
    $privacyRequest->refresh();
    app(TransitionPrivacyRequest::class)->transition(
        request: $privacyRequest,
        operator: $operator,
        nextStatus: PrivacyRequestStatus::Approved,
        expectedCurrentEventId: $privacyRequest->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'export_approved',
        note: 'The export scope and identity were reviewed.',
        evidenceReference: 'privacy-case-2026-0002',
    );
    $privacyRequest->refresh();
    $approved = [
        ...$base,
        '--expected-event' => $privacyRequest->current_event_id,
        '--idempotency' => (string) Str::uuid(),
    ];
    $ordinaryOperator = User::factory()->create();

    $this->artisan('privacy-requests:complete-export', [
        ...$approved,
        '--actor-email' => $ordinaryOperator->email,
    ])->assertFailed();
    $this->artisan('privacy-requests:complete-export', [
        ...$approved,
        '--expected-event' => (string) Str::ulid(),
    ])->assertFailed();
    $this->artisan('privacy-requests:complete-export', [
        ...$approved,
        '--inventory-version' => 'privacy-data-inventory:obsolete',
    ])->assertFailed();
    $this->artisan('privacy-requests:complete-export', [
        ...$approved,
        '--artifact-size-bytes' => 0,
    ])->assertFailed();
    $this->artisan('privacy-requests:complete-export', [
        ...$approved,
        '--artifact-expires-at' => now()->addDays(31)->toIso8601String(),
    ])->assertFailed();

    $deletionId = $this->actingAs($subject)
        ->postJson(
            route('api.v1.me.privacy-requests.store'),
            privacyRequestPayload([
                'type' => 'account_deletion',
                'reason' => 'Please review and close my account safely.',
            ]),
        )
        ->assertCreated()
        ->json('data.id');
    $deletion = PrivacyRequest::query()->findOrFail($deletionId);
    $this->artisan('privacy-requests:complete-export', [
        ...$approved,
        'request' => $deletion->getKey(),
        '--expected-event' => $deletion->current_event_id,
    ])->assertFailed();

    expect(PrivacyRequestFulfillment::query()->exists())->toBeFalse()
        ->and($privacyRequest->refresh()->status)
        ->toBe(PrivacyRequestStatus::Approved);
});

test('approved account erasure removes the personal boundary and leaves an immutable pseudonymous receipt', function () {
    Storage::fake('privacy-erasure-test');
    $subject = User::factory()->create();
    $originalEmail = $subject->email;
    $operator = User::factory()->create();
    $operator->forceFill(['is_super_admin' => true])->save();
    $personalOrganization = app(
        CreatePersonalOrganization::class,
    )->createFor($subject);
    $businessOrganization = Organization::factory()->create([
        'type' => OrganizationType::Business,
    ]);
    OrganizationMembership::query()->create([
        'organization_id' => $businessOrganization->getKey(),
        'user_id' => $subject->getKey(),
        'role' => OrganizationRole::Analyst,
        'joined_at' => now(),
    ]);
    $personalListing = Listing::factory()->create([
        'organization_id' => $personalOrganization->getKey(),
        'created_by_user_id' => $subject->getKey(),
    ]);
    $businessListing = Listing::factory()->create([
        'organization_id' => $businessOrganization->getKey(),
        'created_by_user_id' => $subject->getKey(),
    ]);
    DB::table('listing_images')->insert([
        'id' => (string) Str::ulid(),
        'listing_id' => $personalListing->getKey(),
        'uploaded_by_user_id' => $subject->getKey(),
        'kind' => 'source',
        'disk' => 'privacy-erasure-test',
        'path' => 'already-removed/private-image.jpg',
        'client_filename' => 'private-image.jpg',
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'size_bytes' => 128,
        'width' => 10,
        'height' => 10,
        'checksum_sha256' => str_repeat('a', 64),
        'position' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $invitation = OrganizationInvitation::factory()->create([
        'organization_id' => $businessOrganization->getKey(),
        'email' => $originalEmail,
        'pending_email' => $originalEmail,
        'invited_by_user_id' => $operator->getKey(),
    ]);
    $subject->createToken('privacy-erasure-test');
    DB::table('sessions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $subject->getKey(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'privacy-test',
        'payload' => 'test',
        'last_activity' => now()->timestamp,
    ]);
    DB::table('password_reset_tokens')->insert([
        'email' => $originalEmail,
        'token' => hash('sha256', 'privacy-reset-token'),
        'created_at' => now(),
    ]);
    $privacyRequest = approvedAccountDeletionRequest(
        $subject,
        $operator,
    );
    $options = accountErasureOptions($privacyRequest, $operator);

    $this->artisan('privacy-requests:complete-erasure', [
        'request' => $privacyRequest->getKey(),
        ...$options,
    ])->assertFailed();
    expect(PrivacyRequestFulfillment::query()->exists())->toBeFalse();

    config(['privacy.erasure.enabled' => true]);
    $this->artisan('privacy-requests:complete-erasure', [
        'request' => $privacyRequest->getKey(),
        ...$options,
    ])->assertSuccessful();

    $erased = $subject->fresh();
    $fulfillment = PrivacyRequestFulfillment::query()->firstOrFail();
    $safeReceipt = (new PrivacyRequestFulfillmentResource(
        $fulfillment,
    ))->resolve();

    expect($erased->name)->toBe('Erased account')
        ->and($erased->email)->not->toBe($originalEmail)
        ->and($erased->email)->toEndWith('@privacy.invalid')
        ->and($erased->email_verified_at)->toBeNull()
        ->and($erased->is_super_admin)->toBeFalse()
        ->and($erased->privacy_erased_at)->not->toBeNull()
        ->and($erased->privacy_erasure_request_id)
        ->toBe($privacyRequest->getKey())
        ->and($erased->current_organization_id)->toBeNull()
        ->and(Organization::query()
            ->whereKey($personalOrganization->getKey())
            ->exists())->toBeFalse()
        ->and(Listing::query()
            ->whereKey($personalListing->getKey())
            ->exists())->toBeFalse()
        ->and(Listing::query()
            ->whereKey($businessListing->getKey())
            ->exists())->toBeTrue()
        ->and(OrganizationMembership::query()
            ->where('user_id', $subject->getKey())
            ->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')
            ->where('tokenable_id', $subject->getKey())
            ->exists())->toBeFalse()
        ->and(DB::table('sessions')
            ->where('user_id', $subject->getKey())
            ->exists())->toBeFalse()
        ->and(DB::table('password_reset_tokens')
            ->where('email', $originalEmail)
            ->exists())->toBeFalse()
        ->and($invitation->fresh()->email)
        ->toStartWith('erased-invitation-')
        ->and($invitation->fresh()->pending_email)->toBeNull()
        ->and($privacyRequest->fresh()->status)
        ->toBe(PrivacyRequestStatus::Fulfilled)
        ->and($fulfillment->request_type)
        ->toBe(PrivacyRequestType::AccountDeletion)
        ->and($fulfillment->artifact_reference)->toBeNull()
        ->and($fulfillment->backup_purge_due_at)->not->toBeNull()
        ->and($fulfillment->clearance_references)->toHaveKey(
            'retention_review_required',
        )
        ->and($safeReceipt['backup_purge_due_at'])->not->toBeNull()
        ->and($safeReceipt)->not->toHaveKeys([
            'identity_evidence_reference',
            'erasure_evidence_reference',
            'storage_evidence_reference',
            'processor_evidence_reference',
            'clearance_references',
        ])
        ->and(PlatformAuditEvent::query()
            ->where('action', 'privacy_request.account_erased')
            ->count())->toBe(1);

    config([
        'privacy.erasure.enabled' => false,
        'privacy.erasure.execution_version' => 'privacy-erasure:v2',
        'privacy.erasure.data_inventory_version' => (
            'privacy-erasure-inventory:v5'
        ),
    ]);
    $this->travel(31)->days();
    $this->artisan('privacy-requests:complete-erasure', [
        'request' => $privacyRequest->getKey(),
        ...$options,
    ])->assertSuccessful();
    $this->artisan('privacy-requests:complete-erasure', [
        'request' => $privacyRequest->getKey(),
        ...$options,
        '--storage-evidence' => 'different-storage-evidence',
    ])->assertFailed();

    expect(PrivacyRequestFulfillment::query()->count())->toBe(1)
        ->and(PlatformAuditEvent::query()
            ->where('action', 'privacy_request.account_erased')
            ->count())->toBe(1);
});

test('account erasure fails closed for live ownership private files and incomplete clearance', function () {
    Storage::fake('privacy-erasure-test');
    config(['privacy.erasure.enabled' => true]);
    $subject = User::factory()->create();
    $operator = User::factory()->create();
    $operator->forceFill(['is_super_admin' => true])->save();
    $personalOrganization = app(
        CreatePersonalOrganization::class,
    )->createFor($subject);
    $privacyRequest = approvedAccountDeletionRequest(
        $subject,
        $operator,
    );
    $businessOrganization = Organization::factory()->create([
        'type' => OrganizationType::Business,
    ]);
    $membership = OrganizationMembership::query()->create([
        'organization_id' => $businessOrganization->getKey(),
        'user_id' => $subject->getKey(),
        'role' => OrganizationRole::Owner,
        'joined_at' => now(),
    ]);
    $listing = Listing::factory()->create([
        'organization_id' => $personalOrganization->getKey(),
        'created_by_user_id' => $subject->getKey(),
    ]);
    Storage::disk('privacy-erasure-test')->put(
        'personal/private-image.jpg',
        'private',
    );
    DB::table('listing_images')->insert([
        'id' => (string) Str::ulid(),
        'listing_id' => $listing->getKey(),
        'uploaded_by_user_id' => $subject->getKey(),
        'kind' => 'source',
        'disk' => 'privacy-erasure-test',
        'path' => 'personal/private-image.jpg',
        'client_filename' => 'private-image.jpg',
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'size_bytes' => 7,
        'width' => 10,
        'height' => 10,
        'checksum_sha256' => hash('sha256', 'private'),
        'position' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $options = accountErasureOptions($privacyRequest, $operator);

    $this->artisan('privacy-requests:complete-erasure', [
        'request' => $privacyRequest->getKey(),
        ...$options,
        '--clearance' => [],
    ])->assertFailed();
    $this->artisan('privacy-requests:complete-erasure', [
        'request' => $privacyRequest->getKey(),
        ...$options,
    ])->assertFailed();

    $membership->update(['role' => OrganizationRole::Analyst]);
    $this->artisan('privacy-requests:complete-erasure', [
        'request' => $privacyRequest->getKey(),
        ...$options,
    ])->assertFailed();

    expect($subject->fresh()->email)
        ->not->toEndWith('@privacy.invalid')
        ->and($privacyRequest->fresh()->status)
        ->toBe(PrivacyRequestStatus::Approved)
        ->and(PrivacyRequestFulfillment::query()->exists())->toBeFalse()
        ->and(Organization::query()
            ->whereKey($personalOrganization->getKey())
            ->exists())->toBeTrue();

    Storage::disk('privacy-erasure-test')->delete(
        'personal/private-image.jpg',
    );
    DB::table('subscriptions')->insert([
        'organization_id' => $personalOrganization->getKey(),
        'type' => 'default',
        'stripe_id' => 'sub_live_erasure_blocker',
        'stripe_status' => 'active',
        'stripe_price' => 'price_live_erasure_blocker',
        'quantity' => 1,
        'trial_ends_at' => null,
        'ends_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->artisan('privacy-requests:complete-erasure', [
        'request' => $privacyRequest->getKey(),
        ...$options,
    ])->assertFailed();
    expect(PrivacyRequestFulfillment::query()->exists())->toBeFalse();

    DB::table('subscriptions')
        ->where('stripe_id', 'sub_live_erasure_blocker')
        ->update(['ends_at' => now()]);
    $this->artisan('privacy-requests:complete-erasure', [
        'request' => $privacyRequest->getKey(),
        ...$options,
    ])->assertSuccessful();

    expect($subject->fresh()->privacy_erased_at)->not->toBeNull()
        ->and(PrivacyRequestFulfillment::query()->count())->toBe(1);
});
