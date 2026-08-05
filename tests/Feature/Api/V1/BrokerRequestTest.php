<?php

use App\Actions\BrokerRequests\AcceptBrokerRequestOffer;
use App\Actions\BrokerRequests\GenerateBrokerReport;
use App\Actions\BrokerRequests\OpenBrokerPaymentCase;
use App\Actions\BrokerRequests\PresentBrokerRequestOffer;
use App\Actions\BrokerRequests\PurgeExpiredBrokerReports;
use App\Actions\BrokerRequests\SettleBrokerCommission;
use App\Actions\BrokerRequests\TransitionBrokerPaymentCase;
use App\Actions\BrokerRequests\TransitionBrokerRequest;
use App\Actions\BrokerRequests\TransitionBrokerTransaction;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\BrokerRequests\BrokerCommissionStatus;
use App\Enums\BrokerRequests\BrokerPaymentCaseOutcome;
use App\Enums\BrokerRequests\BrokerPaymentCaseStatus;
use App\Enums\BrokerRequests\BrokerPaymentCaseType;
use App\Enums\BrokerRequests\BrokerReportStatus;
use App\Enums\BrokerRequests\BrokerRequestOfferStatus;
use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Enums\BrokerRequests\BrokerTransactionStatus;
use App\Enums\Localization\SupportedLocale;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Subscriptions\FeatureCode;
use App\Enums\Subscriptions\PlanCode;
use App\Models\BrokerCommission;
use App\Models\BrokerCommissionEvent;
use App\Models\BrokerPaymentCase;
use App\Models\BrokerPaymentCaseEvent;
use App\Models\BrokerReport;
use App\Models\BrokerRequest;
use App\Models\BrokerRequestEvent;
use App\Models\BrokerRequestOfferEvent;
use App\Models\BrokerTransaction;
use App\Models\BrokerTransactionEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Privacy\AccountDeletionBlockerResolver;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
    $this->seed(PlanSeeder::class);
    config([
        'broker.requests_enabled' => true,
        'broker.offers_enabled' => true,
        'broker.transactions_enabled' => true,
        'broker.payment_cases_enabled' => true,
        'broker.commission_rule_version' => 'broker-commission:v1',
        'broker.commission_rate_basis_points' => 250,
        'broker.reports_enabled' => true,
        'broker.report_version' => 'broker-transaction-report:v1',
        'broker.report_disk' => 'local',
        'broker.report_retention_days' => 30,
        'broker.report_download_ttl_minutes' => 10,
        'broker.report_max_bytes' => 5 * 1024 * 1024,
        'broker.report_purge_batch' => 100,
    ]);
});

function brokerWorkspace(
    OrganizationRole $role = OrganizationRole::Owner,
    PlanCode $plan = PlanCode::Business,
): array {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $user,
        'role' => $role,
    ]);
    $user->update(['current_organization_id' => $organization->getKey()]);

    DB::table('organization_plan_assignments')->updateOrInsert(
        ['organization_id' => $organization->getKey()],
        [
            'plan_id' => Plan::query()
                ->where('code', $plan)
                ->where('is_active', true)
                ->valueOrFail('id'),
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    return [$user, $organization];
}

function brokerRequestPayload(array $overrides = []): array
{
    return [
        'title' => 'Used CNC milling machine',
        'product_category_id' => null,
        'product_description' => (
            'We need a production-ready three-axis CNC milling machine '
            .'with documented service history.'
        ),
        'brand_preference' => 'DMG Mori',
        'model_preference' => null,
        'condition_preference' => 'used',
        'quantity' => 1,
        'budget_max_minor' => 4000000,
        'budget_currency_code' => 'EUR',
        'target_country_codes' => ['AT', 'DE'],
        'needed_by' => now()->addMonths(3)->toDateString(),
        'notes' => 'Electrical compatibility and loading access must be verified.',
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/**
 * @return array{BrokerRequest, User, string}
 */
function searchingBrokerRequest(User $owner): array
{
    $created = test()
        ->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.store'),
            brokerRequestPayload(),
        )
        ->assertCreated();
    $submitted = test()
        ->actingAs($owner)
        ->postJson(
            route(
                'api.v1.broker-requests.submit',
                $created->json('data.id'),
            ),
            [
                'expected_current_event_id' => (
                    $created->json('data.current_event_id')
                ),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertAccepted();
    $request = BrokerRequest::query()->findOrFail(
        $created->json('data.id'),
    );
    $operator = User::factory()->create();
    $operator->forceFill(['is_super_admin' => true])->save();
    $transition = app(TransitionBrokerRequest::class);
    $review = $transition->operatorTransition(
        $request,
        $operator,
        BrokerRequestStatus::Reviewing,
        $submitted->json('data.current_event_id'),
        (string) Str::uuid(),
        'operator_review_started',
        'case:broker-review-offer-test',
    );
    $search = $transition->operatorTransition(
        $request->fresh(),
        $operator,
        BrokerRequestStatus::Searching,
        $review->event->getKey(),
        (string) Str::uuid(),
        'operator_search_started',
        'case:broker-search-offer-test',
    );

    return [$request->fresh(), $operator, $search->event->getKey()];
}

function brokerOfferPayload(array $overrides = []): array
{
    return [
        'supplier_display_name' => 'Verified industrial supplier',
        'supplier_reference' => 'vault:supplier-quote-001',
        'item_description' => (
            'Inspected CNC milling machine with loading preparation included.'
        ),
        'condition' => 'used',
        'quantity' => 2,
        'unit_price_minor' => 100000,
        'shipping_cost_minor' => 25000,
        'tax_duty_cost_minor' => 15000,
        'other_cost_minor' => 5000,
        'currency_code' => 'EUR',
        'origin_country_code' => 'DE',
        'estimated_delivery_date' => now()->addMonth()->toDateString(),
        'valid_until' => now()->addDays(7)->toIso8601String(),
        'warranty_months' => 6,
        'return_policy_summary' => 'Return only for a documented material mismatch.',
        ...$overrides,
    ];
}

/**
 * @return array{
 *     BrokerRequest,
 *     User,
 *     BrokerTransaction,
 *     BrokerCommission
 * }
 */
function acceptedBrokerTransaction(User $owner): array
{
    [$request, $operator, $searchEventId] = searchingBrokerRequest($owner);
    $presented = app(PresentBrokerRequestOffer::class)->execute(
        $request,
        $operator,
        $searchEventId,
        (string) Str::uuid(),
        'quote:accepted-transaction-fixture',
        brokerOfferPayload(),
    );
    $accepted = app(AcceptBrokerRequestOffer::class)->execute(
        request: $request->fresh(),
        offer: $presented->offer,
        actor: $owner,
        expectedRequestEventId: $presented->requestEvent->getKey(),
        expectedOfferEventId: $presented->offerEvent->getKey(),
        idempotencyKey: (string) Str::uuid(),
    );

    return [
        $accepted->brokerRequest,
        $operator,
        $accepted->transaction,
        $accepted->commission,
    ];
}

/**
 * @return array{
 *     BrokerRequest,
 *     User,
 *     BrokerTransaction,
 *     BrokerCommission
 * }
 */
function completedBrokerReportTransaction(User $owner): array
{
    [$request, $operator, $transaction, $commission] = (
        acceptedBrokerTransaction($owner)
    );
    $transition = app(TransitionBrokerTransaction::class);
    $current = null;

    foreach ([
        [BrokerTransactionStatus::PaymentConfirmed, 'payment_confirmed', 'ledger:payment'],
        [BrokerTransactionStatus::SupplierOrdered, 'supplier_ordered', 'order:001'],
        [BrokerTransactionStatus::Shipped, 'shipment_confirmed', 'carrier:shipment'],
        [BrokerTransactionStatus::Delivered, 'delivery_confirmed', 'carrier:delivery'],
        [BrokerTransactionStatus::Completed, 'transaction_completed', 'case:complete'],
    ] as [$status, $reason, $evidence]) {
        $fresh = $current?->transaction->fresh() ?? $transaction->fresh();
        $current = $transition->execute(
            transaction: $fresh,
            actor: $operator,
            target: $status,
            expectedCurrentEventId: $fresh->current_event_id,
            idempotencyKey: (string) Str::uuid(),
            reasonCode: $reason,
            evidenceReference: $evidence,
        );
    }

    return [
        $request->fresh(),
        $operator,
        $current->transaction->fresh(),
        $commission->fresh(),
    ];
}

test('broker drafts are tenant safe immutable-event writes with exact replay', function () {
    [$owner, $organization] = brokerWorkspace();
    $payload = brokerRequestPayload();

    $created = $this->actingAs($owner)
        ->postJson(route('api.v1.broker-requests.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.event_sequence', 1)
        ->assertJsonPath('data.target_country_codes.0', 'AT')
        ->assertJsonPath('data.events.0.event_type', 'created');
    $requestId = $created->json('data.id');
    $firstEventId = $created->json('data.current_event_id');

    $this->actingAs($owner)
        ->postJson(route('api.v1.broker-requests.store'), $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $requestId)
        ->assertJsonPath('data.current_event_id', $firstEventId);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.store'),
            [...$payload, 'title' => 'Changed replay'],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');

    $updated = $this->actingAs($owner)
        ->putJson(
            route('api.v1.broker-requests.update', $requestId),
            brokerRequestPayload([
                'title' => 'Updated CNC sourcing brief',
                'expected_current_event_id' => $firstEventId,
            ]),
        )
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated CNC sourcing brief')
        ->assertJsonPath('data.event_sequence', 2)
        ->assertJsonPath('data.events.0.event_type', 'updated');
    $secondEventId = $updated->json('data.current_event_id');

    $this->actingAs($owner)
        ->putJson(
            route('api.v1.broker-requests.update', $requestId),
            brokerRequestPayload([
                'expected_current_event_id' => $firstEventId,
            ]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expected_current_event_id');

    expect(BrokerRequest::query()->count())->toBe(1)
        ->and(BrokerRequestEvent::query()->count())->toBe(2);

    $viewer = User::factory()->create();
    OrganizationMembership::factory()->viewer()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);

    $this->actingAs($viewer)
        ->getJson(route('api.v1.broker-requests.show', $requestId))
        ->assertOk()
        ->assertJsonPath('data.current_event_id', $secondEventId);

    $this->actingAs($viewer)
        ->putJson(
            route('api.v1.broker-requests.update', $requestId),
            brokerRequestPayload([
                'expected_current_event_id' => $secondEventId,
            ]),
        )
        ->assertForbidden();

    [$outsider] = brokerWorkspace();
    $this->actingAs($outsider)
        ->getJson(route('api.v1.broker-requests.show', $requestId))
        ->assertNotFound();
});

test('submission consumes business usage once and free plans fail closed', function () {
    [$owner] = brokerWorkspace();
    $created = $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.store'),
            brokerRequestPayload(),
        )
        ->assertCreated();
    $requestId = $created->json('data.id');
    $eventId = $created->json('data.current_event_id');
    $submitPayload = [
        'expected_current_event_id' => $eventId,
        'idempotency_key' => (string) Str::uuid(),
    ];

    $submitted = $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.submit', $requestId),
            $submitPayload,
        )
        ->assertAccepted()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.event_sequence', 2);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.submit', $requestId),
            $submitPayload,
        )
        ->assertOk()
        ->assertJsonPath(
            'data.current_event_id',
            $submitted->json('data.current_event_id'),
        );

    expect(SubscriptionUsage::query()
        ->where('feature_code', FeatureCode::MonthlyBrokerRequests)
        ->value('used'))->toBe(1)
        ->and(DB::table('subscription_usage_events')
            ->where('feature_code', FeatureCode::MonthlyBrokerRequests->value)
            ->count())->toBe(1);

    $this->actingAs($owner)
        ->putJson(
            route('api.v1.broker-requests.update', $requestId),
            brokerRequestPayload([
                'expected_current_event_id' => (
                    $submitted->json('data.current_event_id')
                ),
            ]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('broker_request');

    [$freeOwner] = brokerWorkspace(plan: PlanCode::Free);
    $freeDraft = $this->actingAs($freeOwner)
        ->postJson(
            route('api.v1.broker-requests.store'),
            brokerRequestPayload(),
        )
        ->assertCreated();

    $this->actingAs($freeOwner)
        ->postJson(
            route(
                'api.v1.broker-requests.submit',
                $freeDraft->json('data.id'),
            ),
            [
                'expected_current_event_id' => (
                    $freeDraft->json('data.current_event_id')
                ),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('broker_request');
});

test('operator workflow is evidence bound and reserves offer transaction states', function () {
    [$owner, $organization] = brokerWorkspace();
    $created = $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.store'),
            brokerRequestPayload(),
        )
        ->assertCreated();
    $submitted = $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.broker-requests.submit',
                $created->json('data.id'),
            ),
            [
                'expected_current_event_id' => (
                    $created->json('data.current_event_id')
                ),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertAccepted();
    $request = BrokerRequest::query()->findOrFail(
        $created->json('data.id'),
    );
    $operator = User::factory()->create();
    $operator->forceFill(['is_super_admin' => true])->save();
    $operator = $operator->fresh();
    $action = app(TransitionBrokerRequest::class);
    $reviewKey = (string) Str::uuid();

    $review = $action->operatorTransition(
        $request,
        $operator,
        BrokerRequestStatus::Reviewing,
        $submitted->json('data.current_event_id'),
        $reviewKey,
        'operator_review_started',
        'case:broker-review-001',
    );
    $replay = $action->operatorTransition(
        $request,
        $operator,
        BrokerRequestStatus::Reviewing,
        $submitted->json('data.current_event_id'),
        $reviewKey,
        'operator_review_started',
        'case:broker-review-001',
    );

    expect($review->created)->toBeTrue()
        ->and($replay->created)->toBeFalse()
        ->and($replay->event->is($review->event))->toBeTrue()
        ->and($request->fresh()->status)->toBe(BrokerRequestStatus::Reviewing);

    expect(fn () => $action->operatorTransition(
        $request->fresh(),
        $operator,
        BrokerRequestStatus::OffersAvailable,
        $review->event->getKey(),
        (string) Str::uuid(),
        'offers_prepared',
        'case:broker-offer-001',
    ))->toThrow(ValidationException::class);

    $search = $action->operatorTransition(
        $request->fresh(),
        $operator,
        BrokerRequestStatus::Searching,
        $review->event->getKey(),
        (string) Str::uuid(),
        'operator_search_started',
        'case:broker-search-001',
    );

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.cancel', $request),
            [
                'expected_current_event_id' => $search->event->getKey(),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonMissingPath('data.events.0.evidence_reference');

    expect(BrokerRequest::query()
        ->forOrganization($organization)
        ->sole()
        ->resolved_at)->not->toBeNull();
});

test('broker write paths can be disabled without hiding tenant history', function () {
    [$owner] = brokerWorkspace();
    $created = $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.store'),
            brokerRequestPayload(),
        )
        ->assertCreated();

    config(['broker.requests_enabled' => false]);

    $this->actingAs($owner)
        ->getJson(route(
            'api.v1.broker-requests.show',
            $created->json('data.id'),
        ))
        ->assertOk();

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.store'),
            brokerRequestPayload(),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('broker_request');
});

test('an active personal broker request blocks account erasure until resolved', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->personal($owner)->create();
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    $owner->update(['current_organization_id' => $organization->getKey()]);
    DB::table('organization_plan_assignments')->insert([
        'organization_id' => $organization->getKey(),
        'plan_id' => Plan::query()
            ->where('code', PlanCode::Business)
            ->valueOrFail('id'),
        'starts_at' => now()->subMinute(),
        'ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $created = $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.store'),
            brokerRequestPayload(),
        )
        ->assertCreated();
    $resolver = app(AccountDeletionBlockerResolver::class);

    expect($resolver->live($owner))->toContain(
        'active_broker_request_resolution_required',
    );

    $this->actingAs($owner)
        ->postJson(
            route(
                'api.v1.broker-requests.cancel',
                $created->json('data.id'),
            ),
            [
                'expected_current_event_id' => (
                    $created->json('data.current_event_id')
                ),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertOk();

    expect($resolver->live($owner))->not->toContain(
        'active_broker_request_resolution_required',
    );
});

test('operator offers and subject acceptance are exact atomic tenant-safe writes', function () {
    [$owner, $organization] = brokerWorkspace();
    [$request, $operator, $searchEventId] = searchingBrokerRequest($owner);
    $present = app(PresentBrokerRequestOffer::class);
    $firstKey = (string) Str::uuid();
    $first = $present->execute(
        $request,
        $operator,
        $searchEventId,
        $firstKey,
        'quote:supplier-a:revision-1',
        brokerOfferPayload(),
    );
    $replay = $present->execute(
        $request,
        $operator,
        $searchEventId,
        $firstKey,
        'quote:supplier-a:revision-1',
        brokerOfferPayload(),
    );

    expect($first->created)->toBeTrue()
        ->and($replay->created)->toBeFalse()
        ->and($replay->offer->is($first->offer))->toBeTrue()
        ->and($first->offer->item_subtotal_minor)->toBe(200000)
        ->and($first->offer->total_minor)->toBe(245000)
        ->and($first->offer->commission_rate_basis_points)->toBe(250)
        ->and($first->offer->commission_base_minor)->toBe(245000)
        ->and($first->offer->commission_amount_minor)->toBe(6125)
        ->and($first->offer->payable_total_minor)->toBe(251125)
        ->and($first->brokerRequest->status)->toBe(
            BrokerRequestStatus::OffersAvailable,
        );

    expect(fn () => $present->execute(
        $request,
        $operator,
        $searchEventId,
        $firstKey,
        'quote:supplier-a:revision-1',
        brokerOfferPayload(['unit_price_minor' => 100001]),
    ))->toThrow(ValidationException::class);

    $second = $present->execute(
        $request->fresh(),
        $operator,
        $first->requestEvent->getKey(),
        (string) Str::uuid(),
        'quote:supplier-b:revision-1',
        brokerOfferPayload([
            'supplier_display_name' => 'Second verified supplier',
            'supplier_reference' => 'vault:supplier-quote-002',
            'unit_price_minor' => 95000,
            'shipping_cost_minor' => 30000,
            'currency_code' => 'USD',
            'origin_country_code' => 'US',
        ]),
    );

    $shown = $this->actingAs($owner)
        ->getJson(route('api.v1.broker-requests.show', $request))
        ->assertOk()
        ->assertJsonCount(2, 'data.offers')
        ->assertJsonMissingPath('data.offers.0.supplier_reference')
        ->assertJsonMissingPath('data.offers.0.events.0.evidence_reference')
        ->assertJsonMissingPath('data.offers.0.events.0.payload_hash');

    expect(collect($shown->json('data.offers'))
        ->pluck('currency_code')
        ->sort()
        ->values()
        ->all())->toBe(['EUR', 'USD']);

    $acceptKey = (string) Str::uuid();
    $accepted = $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.offers.accept', [
                'brokerRequest' => $request,
                'offer' => $first->offer,
            ]),
            [
                'expected_request_event_id' => (
                    $second->requestEvent->getKey()
                ),
                'expected_offer_event_id' => (
                    $first->offerEvent->getKey()
                ),
                'idempotency_key' => $acceptKey,
            ],
        )
        ->assertAccepted()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.events.0.event_type', 'offer_accepted')
        ->assertJsonPath('data.transaction.status', 'awaiting_payment')
        ->assertJsonPath('data.transaction.supplier_total_minor', 245000)
        ->assertJsonPath('data.transaction.commission_amount_minor', 6125)
        ->assertJsonPath('data.transaction.payable_total_minor', 251125)
        ->assertJsonPath('data.transaction.commission.status', 'pending')
        ->assertJsonMissingPath(
            'data.transaction.events.0.evidence_reference',
        )
        ->assertJsonMissingPath('data.transaction.events.0.payload_hash');

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.offers.accept', [
                'brokerRequest' => $request,
                'offer' => $first->offer,
            ]),
            [
                'expected_request_event_id' => (
                    $second->requestEvent->getKey()
                ),
                'expected_offer_event_id' => (
                    $first->offerEvent->getKey()
                ),
                'idempotency_key' => $acceptKey,
            ],
        )
        ->assertOk()
        ->assertJsonPath(
            'data.current_event_id',
            $accepted->json('data.current_event_id'),
        );

    expect($first->offer->fresh()->status)->toBe(
        BrokerRequestOfferStatus::Accepted,
    )->and($second->offer->fresh()->status)->toBe(
        BrokerRequestOfferStatus::NotSelected,
    )->and(BrokerRequestOfferEvent::query()
        ->where('event_type', 'accepted')
        ->count())->toBe(1)
        ->and(BrokerRequestOfferEvent::query()
            ->where('event_type', 'not_selected')
            ->count())->toBe(1)
        ->and(BrokerRequest::query()
            ->forOrganization($organization)
            ->sole()
            ->status)->toBe(BrokerRequestStatus::Accepted);

    $viewer = User::factory()->create();
    OrganizationMembership::factory()->viewer()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);

    $this->actingAs($viewer)
        ->postJson(
            route('api.v1.broker-requests.offers.accept', [
                'brokerRequest' => $request,
                'offer' => $second->offer,
            ]),
            [
                'expected_request_event_id' => (
                    $accepted->json('data.current_event_id')
                ),
                'expected_offer_event_id' => (
                    $second->offer->fresh()->current_event_id
                ),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertForbidden();

    [$outsider] = brokerWorkspace();
    $this->actingAs($outsider)
        ->postJson(
            route('api.v1.broker-requests.offers.accept', [
                'brokerRequest' => $request,
                'offer' => $first->offer,
            ]),
            [
                'expected_request_event_id' => (
                    $accepted->json('data.current_event_id')
                ),
                'expected_offer_event_id' => (
                    $first->offer->fresh()->current_event_id
                ),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertNotFound();
});

test('broker transaction lifecycle is exact replay safe and completes commission evidence', function () {
    [$owner] = brokerWorkspace();
    [$request, $operator, $transaction, $commission] = (
        acceptedBrokerTransaction($owner)
    );
    $transition = app(TransitionBrokerTransaction::class);
    $paymentKey = (string) Str::uuid();
    $payment = $transition->execute(
        transaction: $transaction,
        actor: $operator,
        target: BrokerTransactionStatus::PaymentConfirmed,
        expectedCurrentEventId: $transaction->current_event_id,
        idempotencyKey: $paymentKey,
        reasonCode: 'external_payment_confirmed',
        evidenceReference: 'payment-ledger:payment-001',
    );
    $paymentReplay = $transition->execute(
        transaction: $transaction,
        actor: $operator,
        target: BrokerTransactionStatus::PaymentConfirmed,
        expectedCurrentEventId: $transaction->current_event_id,
        idempotencyKey: $paymentKey,
        reasonCode: 'external_payment_confirmed',
        evidenceReference: 'payment-ledger:payment-001',
    );

    expect($payment->created)->toBeTrue()
        ->and($paymentReplay->created)->toBeFalse()
        ->and($paymentReplay->transactionEvent->is(
            $payment->transactionEvent,
        ))->toBeTrue();

    expect(fn () => $transition->execute(
        transaction: $transaction,
        actor: $operator,
        target: BrokerTransactionStatus::PaymentConfirmed,
        expectedCurrentEventId: $transaction->current_event_id,
        idempotencyKey: $paymentKey,
        reasonCode: 'changed_payment_reason',
        evidenceReference: 'payment-ledger:payment-001',
    ))->toThrow(ValidationException::class);

    $current = $payment;

    foreach ([
        [
            BrokerTransactionStatus::SupplierOrdered,
            'supplier_order_confirmed',
            'supplier-order:order-001',
        ],
        [
            BrokerTransactionStatus::Shipped,
            'supplier_shipment_confirmed',
            'carrier:shipment-001',
        ],
        [
            BrokerTransactionStatus::Delivered,
            'delivery_confirmed',
            'carrier:delivery-001',
        ],
        [
            BrokerTransactionStatus::Completed,
            'transaction_completed',
            'case:completion-001',
        ],
    ] as [$status, $reason, $evidence]) {
        $current = $transition->execute(
            transaction: $current->transaction,
            actor: $operator,
            target: $status,
            expectedCurrentEventId: $current->transactionEvent->getKey(),
            idempotencyKey: (string) Str::uuid(),
            reasonCode: $reason,
            evidenceReference: $evidence,
        );
    }

    expect($current->transaction->status)->toBe(
        BrokerTransactionStatus::Completed,
    )->and($request->fresh()->status)->toBe(BrokerRequestStatus::Completed)
        ->and($commission->fresh()->status)->toBe(
            BrokerCommissionStatus::Earned,
        )
        ->and(BrokerTransactionEvent::query()->count())->toBe(6)
        ->and(BrokerCommissionEvent::query()->count())->toBe(2);

    $settleKey = (string) Str::uuid();
    $settlement = app(SettleBrokerCommission::class)->execute(
        commission: $commission->fresh(),
        actor: $operator,
        expectedCurrentEventId: $commission->fresh()->current_event_id,
        idempotencyKey: $settleKey,
        reasonCode: 'external_commission_settled',
        evidenceReference: 'settlement-ledger:settlement-001',
    );
    $settlementReplay = app(SettleBrokerCommission::class)->execute(
        commission: $commission->fresh(),
        actor: $operator,
        expectedCurrentEventId: $settlement->event->previous_event_id,
        idempotencyKey: $settleKey,
        reasonCode: 'external_commission_settled',
        evidenceReference: 'settlement-ledger:settlement-001',
    );

    expect($settlement->commission->status)->toBe(
        BrokerCommissionStatus::Settled,
    )->and($settlementReplay->created)->toBeFalse()
        ->and(BrokerCommissionEvent::query()->count())->toBe(3);

    $shown = $this->actingAs($owner)
        ->getJson(route('api.v1.broker-requests.show', $request))
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.transaction.status', 'completed')
        ->assertJsonPath('data.transaction.commission.status', 'settled')
        ->assertJsonCount(6, 'data.transaction.events')
        ->assertJsonCount(3, 'data.transaction.commission.events');

    expect(json_encode($shown->json('data.transaction')))
        ->not->toContain('payment-ledger')
        ->not->toContain('settlement-ledger')
        ->not->toContain('payload_hash')
        ->not->toContain('snapshot')
        ->not->toContain('idempotency');
});

test('broker transaction cancellation and safety boundaries fail closed', function () {
    [$owner] = brokerWorkspace();
    [$request, $operator, $transaction, $commission] = (
        acceptedBrokerTransaction($owner)
    );
    $transition = app(TransitionBrokerTransaction::class);

    expect(fn () => $transition->execute(
        transaction: $transaction,
        actor: $owner,
        target: BrokerTransactionStatus::Cancelled,
        expectedCurrentEventId: $transaction->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'subject_cannot_operate',
        evidenceReference: 'case:forbidden',
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $transition->execute(
        transaction: $transaction,
        actor: $operator,
        target: BrokerTransactionStatus::SupplierOrdered,
        expectedCurrentEventId: $transaction->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'skipped_payment',
        evidenceReference: 'case:invalid-transition',
    ))->toThrow(ValidationException::class);

    $cancelled = $transition->execute(
        transaction: $transaction,
        actor: $operator,
        target: BrokerTransactionStatus::Cancelled,
        expectedCurrentEventId: $transaction->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'prepayment_transaction_cancelled',
        evidenceReference: 'case:cancellation-001',
    );

    expect($cancelled->transaction->status)->toBe(
        BrokerTransactionStatus::Cancelled,
    )->and($request->fresh()->status)->toBe(BrokerRequestStatus::Cancelled)
        ->and($commission->fresh()->status)->toBe(
            BrokerCommissionStatus::Waived,
        );

    expect(fn () => app(SettleBrokerCommission::class)->execute(
        commission: $commission->fresh(),
        actor: $operator,
        expectedCurrentEventId: $commission->fresh()->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'waived_cannot_settle',
        evidenceReference: 'settlement-ledger:invalid',
    ))->toThrow(ValidationException::class);

    config(['broker.transactions_enabled' => false]);

    expect(fn () => $transition->execute(
        transaction: $transaction,
        actor: $operator,
        target: BrokerTransactionStatus::PaymentConfirmed,
        expectedCurrentEventId: $transaction->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'disabled_transition',
        evidenceReference: 'case:disabled',
    ))->toThrow(ValidationException::class);

    expect(fn () => $transaction->forceFill([
        'payable_total_minor' => 1,
    ])->save())->toThrow(LogicException::class);
    expect(fn () => $cancelled->transactionEvent->delete())
        ->toThrow(LogicException::class);
});

test('broker refund case is exact replay safe immutable and subject safe', function () {
    [$owner] = brokerWorkspace();
    [$request, $operator, $transaction] = acceptedBrokerTransaction($owner);
    $payment = app(TransitionBrokerTransaction::class)->execute(
        transaction: $transaction,
        actor: $operator,
        target: BrokerTransactionStatus::PaymentConfirmed,
        expectedCurrentEventId: $transaction->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'external_payment_confirmed',
        evidenceReference: 'payment-ledger:case-foundation-payment',
    );
    $openKey = (string) Str::uuid();
    $open = app(OpenBrokerPaymentCase::class)->execute(
        transaction: $payment->transaction,
        actor: $operator,
        type: BrokerPaymentCaseType::Refund,
        requestedAmountMinor: 50000,
        expectedTransactionEventId: $payment->transactionEvent->getKey(),
        idempotencyKey: $openKey,
        externalCaseReference: 'support:refund-case-001',
        reasonCode: 'customer_refund_requested',
        evidenceReference: 'case-evidence:refund-request-001',
    );
    $replay = app(OpenBrokerPaymentCase::class)->execute(
        transaction: $payment->transaction,
        actor: $operator,
        type: BrokerPaymentCaseType::Refund,
        requestedAmountMinor: 50000,
        expectedTransactionEventId: $payment->transactionEvent->getKey(),
        idempotencyKey: $openKey,
        externalCaseReference: 'support:refund-case-001',
        reasonCode: 'customer_refund_requested',
        evidenceReference: 'case-evidence:refund-request-001',
    );

    expect($open->created)->toBeTrue()
        ->and($replay->created)->toBeFalse()
        ->and($replay->paymentCase->is($open->paymentCase))->toBeTrue()
        ->and($open->paymentCase->currency_code)->toBe('EUR')
        ->and($open->paymentCase->status)->toBe(BrokerPaymentCaseStatus::Open)
        ->and(BrokerPaymentCase::query()->count())->toBe(1)
        ->and(BrokerPaymentCaseEvent::query()->count())->toBe(1);

    expect(fn () => app(OpenBrokerPaymentCase::class)->execute(
        transaction: $payment->transaction,
        actor: $operator,
        type: BrokerPaymentCaseType::Refund,
        requestedAmountMinor: 50001,
        expectedTransactionEventId: $payment->transactionEvent->getKey(),
        idempotencyKey: $openKey,
        externalCaseReference: 'support:refund-case-001',
        reasonCode: 'customer_refund_requested',
        evidenceReference: 'case-evidence:refund-request-001',
    ))->toThrow(ValidationException::class);

    $transition = app(TransitionBrokerPaymentCase::class);
    $review = $transition->execute(
        paymentCase: $open->paymentCase,
        actor: $operator,
        target: BrokerPaymentCaseStatus::UnderReview,
        expectedCurrentEventId: $open->event->getKey(),
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'refund_review_started',
        evidenceReference: 'case-evidence:refund-review-001',
    );
    $resolved = $transition->execute(
        paymentCase: $review->paymentCase,
        actor: $operator,
        target: BrokerPaymentCaseStatus::Resolved,
        expectedCurrentEventId: $review->event->getKey(),
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'external_refund_confirmed',
        evidenceReference: 'payment-ledger:refund-001',
        outcome: BrokerPaymentCaseOutcome::RefundConfirmed,
        resolvedAmountMinor: 40000,
    );

    expect($resolved->paymentCase->status)->toBe(
        BrokerPaymentCaseStatus::Resolved,
    )->and($resolved->paymentCase->resolution_outcome)->toBe(
        BrokerPaymentCaseOutcome::RefundConfirmed,
    )->and($resolved->paymentCase->resolved_amount_minor)->toBe(40000)
        ->and($resolved->paymentCase->event_sequence)->toBe(3);

    $shown = $this->actingAs($owner)
        ->getJson(route('api.v1.broker-requests.show', $request))
        ->assertOk()
        ->assertJsonPath(
            'data.transaction.payment_cases.0.status',
            'resolved',
        )
        ->assertJsonPath(
            'data.transaction.payment_cases.0.resolution_outcome',
            'refund_confirmed',
        )
        ->assertJsonCount(3, 'data.transaction.payment_cases.0.events');
    $projection = json_encode(
        $shown->json('data.transaction.payment_cases.0'),
    );

    expect($projection)
        ->not->toContain('support:refund-case-001')
        ->not->toContain('payment-ledger:refund-001')
        ->not->toContain('logical_case_hash')
        ->not->toContain('snapshot')
        ->not->toContain('payload_hash')
        ->not->toContain('idempotency');
    expect(fn () => $resolved->paymentCase->forceFill([
        'requested_amount_minor' => 1,
    ])->save())->toThrow(LogicException::class);
    expect(fn () => $resolved->event->delete())->toThrow(LogicException::class);
});

test('broker payment-case boundaries and personal erasure blocker fail closed', function () {
    [$owner, $organization] = brokerWorkspace();
    DB::table('organizations')
        ->where('id', $organization->getKey())
        ->update([
            'type' => 'personal',
            'personal_user_id' => $owner->getKey(),
        ]);
    [, $operator, $transaction] = acceptedBrokerTransaction($owner);
    $open = app(OpenBrokerPaymentCase::class);

    expect(fn () => $open->execute(
        transaction: $transaction,
        actor: $operator,
        type: BrokerPaymentCaseType::Dispute,
        requestedAmountMinor: 1000,
        expectedTransactionEventId: $transaction->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        externalCaseReference: 'support:premature-dispute',
        reasonCode: 'premature_dispute',
        evidenceReference: 'case-evidence:premature',
    ))->toThrow(ValidationException::class);

    $payment = app(TransitionBrokerTransaction::class)->execute(
        transaction: $transaction,
        actor: $operator,
        target: BrokerTransactionStatus::PaymentConfirmed,
        expectedCurrentEventId: $transaction->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'external_payment_confirmed',
        evidenceReference: 'payment-ledger:dispute-payment',
    );

    expect(fn () => $open->execute(
        transaction: $payment->transaction,
        actor: $owner,
        type: BrokerPaymentCaseType::Dispute,
        requestedAmountMinor: 1000,
        expectedTransactionEventId: $payment->transactionEvent->getKey(),
        idempotencyKey: (string) Str::uuid(),
        externalCaseReference: 'support:unauthorized-dispute',
        reasonCode: 'unauthorized_dispute',
        evidenceReference: 'case-evidence:unauthorized',
    ))->toThrow(AuthorizationException::class);
    expect(fn () => $open->execute(
        transaction: $payment->transaction,
        actor: $operator,
        type: BrokerPaymentCaseType::Dispute,
        requestedAmountMinor: $payment->transaction->payable_total_minor + 1,
        expectedTransactionEventId: $payment->transactionEvent->getKey(),
        idempotencyKey: (string) Str::uuid(),
        externalCaseReference: 'support:oversized-dispute',
        reasonCode: 'oversized_dispute',
        evidenceReference: 'case-evidence:oversized',
    ))->toThrow(ValidationException::class);

    $case = $open->execute(
        transaction: $payment->transaction,
        actor: $operator,
        type: BrokerPaymentCaseType::Dispute,
        requestedAmountMinor: 25000,
        expectedTransactionEventId: $payment->transactionEvent->getKey(),
        idempotencyKey: (string) Str::uuid(),
        externalCaseReference: 'support:dispute-case-001',
        reasonCode: 'external_dispute_opened',
        evidenceReference: 'case-evidence:dispute-open-001',
    );

    expect(app(AccountDeletionBlockerResolver::class)->live($owner))
        ->toContain('broker_payment_case_resolution_required');
    expect(fn () => $open->execute(
        transaction: $payment->transaction,
        actor: $operator,
        type: BrokerPaymentCaseType::Dispute,
        requestedAmountMinor: 25000,
        expectedTransactionEventId: $payment->transactionEvent->getKey(),
        idempotencyKey: (string) Str::uuid(),
        externalCaseReference: 'SUPPORT:DISPUTE-CASE-001',
        reasonCode: 'duplicate_external_case',
        evidenceReference: 'case-evidence:duplicate',
    ))->toThrow(ValidationException::class);

    $transition = app(TransitionBrokerPaymentCase::class);
    expect(fn () => $transition->execute(
        paymentCase: $case->paymentCase,
        actor: $operator,
        target: BrokerPaymentCaseStatus::Resolved,
        expectedCurrentEventId: $case->event->getKey(),
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'skip_review',
        evidenceReference: 'case-evidence:skip-review',
        outcome: BrokerPaymentCaseOutcome::DisputeWon,
        resolvedAmountMinor: 0,
    ))->toThrow(ValidationException::class);
    $review = $transition->execute(
        paymentCase: $case->paymentCase,
        actor: $operator,
        target: BrokerPaymentCaseStatus::UnderReview,
        expectedCurrentEventId: $case->event->getKey(),
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'dispute_review_started',
        evidenceReference: 'case-evidence:dispute-review-001',
    );
    expect(fn () => $transition->execute(
        paymentCase: $review->paymentCase,
        actor: $operator,
        target: BrokerPaymentCaseStatus::Resolved,
        expectedCurrentEventId: $review->event->getKey(),
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'wrong_outcome_type',
        evidenceReference: 'case-evidence:wrong-outcome',
        outcome: BrokerPaymentCaseOutcome::RefundConfirmed,
        resolvedAmountMinor: 1,
    ))->toThrow(ValidationException::class);
    $resolved = $transition->execute(
        paymentCase: $review->paymentCase,
        actor: $operator,
        target: BrokerPaymentCaseStatus::Resolved,
        expectedCurrentEventId: $review->event->getKey(),
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'external_dispute_won',
        evidenceReference: 'payment-ledger:dispute-won-001',
        outcome: BrokerPaymentCaseOutcome::DisputeWon,
        resolvedAmountMinor: 0,
    );

    expect($resolved->paymentCase->status)->toBe(
        BrokerPaymentCaseStatus::Resolved,
    )->and(app(AccountDeletionBlockerResolver::class)->live($owner))
        ->not->toContain('broker_payment_case_resolution_required');

    config(['broker.payment_cases_enabled' => false]);
    expect(fn () => $transition->execute(
        paymentCase: $resolved->paymentCase,
        actor: $operator,
        target: BrokerPaymentCaseStatus::Cancelled,
        expectedCurrentEventId: $resolved->event->getKey(),
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'disabled_case_write',
        evidenceReference: 'case-evidence:disabled',
    ))->toThrow(ValidationException::class);
});

test('broker payment-case CLI uses the same audited action contract', function () {
    [$owner] = brokerWorkspace();
    [, $operator, $transaction] = acceptedBrokerTransaction($owner);
    $payment = app(TransitionBrokerTransaction::class)->execute(
        transaction: $transaction,
        actor: $operator,
        target: BrokerTransactionStatus::PaymentConfirmed,
        expectedCurrentEventId: $transaction->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        reasonCode: 'external_payment_confirmed',
        evidenceReference: 'payment-ledger:cli-payment',
    );

    $this->artisan('broker-payment-cases:open', [
        'transaction' => $transaction->getKey(),
        'type' => 'refund',
        '--actor-email' => $operator->email,
        '--expected-transaction-event' => $payment->transactionEvent->getKey(),
        '--idempotency' => (string) Str::uuid(),
        '--amount-minor' => '10000',
        '--external-case' => 'support:refund-cli-001',
        '--reason-code' => 'refund_case_opened',
        '--evidence' => 'case-evidence:refund-cli-open',
    ])->assertSuccessful();
    $case = BrokerPaymentCase::query()->sole();

    $this->artisan('broker-payment-cases:transition', [
        'payment-case' => $case->getKey(),
        'status' => 'under_review',
        '--actor-email' => $operator->email,
        '--expected-event' => $case->current_event_id,
        '--idempotency' => (string) Str::uuid(),
        '--reason-code' => 'refund_review_started',
        '--evidence' => 'case-evidence:refund-cli-review',
    ])->assertSuccessful();

    expect($case->fresh()->status)->toBe(
        BrokerPaymentCaseStatus::UnderReview,
    )->and($case->events()->count())->toBe(2);
});

test('completed transaction generates one immutable subject-safe private PDF with exact replay', function () {
    Storage::fake('local');
    [$owner] = brokerWorkspace();
    [, $operator, $transaction, $commission] = (
        completedBrokerReportTransaction($owner)
    );
    $idempotency = (string) Str::uuid();
    $action = app(GenerateBrokerReport::class);
    $result = $action->execute(
        transaction: $transaction,
        actor: $operator,
        expectedTransactionEventId: $transaction->current_event_id,
        expectedCommissionEventId: $commission->current_event_id,
        idempotencyKey: $idempotency,
        locale: SupportedLocale::SerbianLatin,
        reasonCode: 'completed_transaction_report',
        evidenceReference: 'case:report-generation-001',
    );

    expect($result->created)->toBeTrue()
        ->and($result->report->status)->toBe(BrokerReportStatus::Available)
        ->and($result->report->page_count)->toBeGreaterThan(0)
        ->and($result->report->artifact_size_bytes)->toBeGreaterThan(1000)
        ->and($result->report->report_snapshot['locale'])->toBe('sr-Latn')
        ->and(json_encode($result->report->report_snapshot))
        ->not->toContain('vault:supplier-quote-001')
        ->not->toContain('case:report-generation-001')
        ->not->toContain('Electrical compatibility')
        ->not->toContain('idempotency')
        ->not->toContain('payload_hash');
    Storage::disk('local')->assertExists($result->report->path);
    $bytes = Storage::disk('local')->get($result->report->path);

    expect(str_starts_with($bytes, '%PDF-'))->toBeTrue()
        ->and(hash('sha256', $bytes))->toBe(
            $result->report->artifact_sha256,
        );

    $replay = $action->execute(
        transaction: $transaction,
        actor: $operator,
        expectedTransactionEventId: $transaction->current_event_id,
        expectedCommissionEventId: $commission->current_event_id,
        idempotencyKey: $idempotency,
        locale: SupportedLocale::SerbianLatin,
        reasonCode: 'completed_transaction_report',
        evidenceReference: 'case:report-generation-001',
    );

    expect($replay->created)->toBeFalse()
        ->and($replay->report->getKey())->toBe($result->report->getKey())
        ->and(BrokerReport::query()->count())->toBe(1)
        ->and($result->report->events()->count())->toBe(1);

    expect(fn () => $action->execute(
        transaction: $transaction,
        actor: $operator,
        expectedTransactionEventId: $transaction->current_event_id,
        expectedCommissionEventId: $commission->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        locale: SupportedLocale::SerbianLatin,
        reasonCode: 'completed_transaction_report',
        evidenceReference: 'case:report-generation-001',
    ))->toThrow(ValidationException::class);

    expect(fn () => $result->report->forceFill([
        'artifact_sha256' => str_repeat('0', 64),
    ])->save())->toThrow(LogicException::class);
});

test('broker report projection and signed download are tenant safe and integrity checked', function () {
    Storage::fake('local');
    [$owner, $organization] = brokerWorkspace();
    [, $operator, $transaction, $commission] = (
        completedBrokerReportTransaction($owner)
    );
    $report = app(GenerateBrokerReport::class)->execute(
        transaction: $transaction,
        actor: $operator,
        expectedTransactionEventId: $transaction->current_event_id,
        expectedCommissionEventId: $commission->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        locale: SupportedLocale::English,
        reasonCode: 'completed_transaction_report',
        evidenceReference: 'case:report-download-001',
    )->report;
    $shown = $this->actingAs($owner)
        ->getJson(route('api.v1.broker-requests.show', $transaction->broker_request_id))
        ->assertOk()
        ->assertJsonPath('data.transaction.reports.0.id', $report->getKey())
        ->assertJsonPath('data.transaction.reports.0.status', 'available');
    $projectionData = $shown->json('data.transaction.reports.0');
    $projection = json_encode($projectionData);

    expect($projection)
        ->not->toContain($report->path)
        ->not->toContain($report->report_hash)
        ->not->toContain('report_snapshot')
        ->not->toContain('source_transaction_event_id')
        ->and(array_keys($projectionData))
        ->not->toContain('disk', 'path');

    $downloadUrl = $shown->json('data.transaction.reports.0.download_url');
    $this->actingAs($owner)
        ->get($downloadUrl)
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('cache-control', 'max-age=0, no-store, private')
        ->assertHeader('x-content-type-options', 'nosniff');

    [$otherOwner] = brokerWorkspace();
    $otherOwner->update(['current_organization_id' => $organization->getKey()]);
    $this->actingAs($otherOwner)->get($downloadUrl)->assertNotFound();

    Storage::disk('local')->put($report->path, 'corrupt');
    $this->actingAs($owner)->get($downloadUrl)->assertNotFound();
});

test('expired broker report artifacts are purged with an immutable event and block erasure beforehand', function () {
    Storage::fake('local');
    [$owner, $organization] = brokerWorkspace();
    DB::table('organizations')
        ->where('id', $organization->getKey())
        ->update([
            'type' => 'personal',
            'personal_user_id' => $owner->getKey(),
        ]);
    [, $operator, $transaction, $commission] = (
        completedBrokerReportTransaction($owner)
    );
    $report = app(GenerateBrokerReport::class)->execute(
        transaction: $transaction,
        actor: $operator,
        expectedTransactionEventId: $transaction->current_event_id,
        expectedCommissionEventId: $commission->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        locale: SupportedLocale::German,
        reasonCode: 'completed_transaction_report',
        evidenceReference: 'case:report-purge-001',
    )->report;

    expect(app(AccountDeletionBlockerResolver::class)->live($owner))
        ->toContain('broker_report_artifact_purge_required');

    DB::table('broker_reports')
        ->where('id', $report->getKey())
        ->update(['artifact_expires_at' => now()->subMinute()]);
    $purge = app(PurgeExpiredBrokerReports::class)->execute();
    $report->refresh();

    expect($purge->processed)->toBe(1)
        ->and($purge->purged)->toBe(1)
        ->and($purge->failed)->toBe(0)
        ->and($report->status)->toBe(BrokerReportStatus::Purged)
        ->and($report->purged_at)->not->toBeNull()
        ->and($report->event_sequence)->toBe(2)
        ->and($report->currentEvent->event_type->value)->toBe('purged')
        ->and(app(AccountDeletionBlockerResolver::class)->live($owner))
        ->not->toContain('broker_report_artifact_purge_required');
    Storage::disk('local')->assertMissing($report->path);
});

test('broker report generation rejects unauthorized, incomplete and stale sources', function () {
    Storage::fake('local');
    [$owner] = brokerWorkspace();
    [, $operator, $transaction, $commission] = acceptedBrokerTransaction($owner);
    $action = app(GenerateBrokerReport::class);

    expect(fn () => $action->execute(
        transaction: $transaction,
        actor: $operator,
        expectedTransactionEventId: $transaction->current_event_id,
        expectedCommissionEventId: $commission->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        locale: SupportedLocale::English,
        reasonCode: 'premature_report',
        evidenceReference: 'case:premature',
    ))->toThrow(ValidationException::class);

    [, , $completed, $earnedCommission] = (
        completedBrokerReportTransaction($owner)
    );
    expect(fn () => $action->execute(
        transaction: $completed,
        actor: $owner,
        expectedTransactionEventId: $completed->current_event_id,
        expectedCommissionEventId: $earnedCommission->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        locale: SupportedLocale::English,
        reasonCode: 'unauthorized_report',
        evidenceReference: 'case:unauthorized',
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $action->execute(
        transaction: $completed,
        actor: $operator,
        expectedTransactionEventId: (string) Str::ulid(),
        expectedCommissionEventId: $earnedCommission->current_event_id,
        idempotencyKey: (string) Str::uuid(),
        locale: SupportedLocale::English,
        reasonCode: 'stale_report',
        evidenceReference: 'case:stale',
    ))->toThrow(ValidationException::class);
});

test('broker report CLI generates through the same audited action contract', function () {
    Storage::fake('local');
    [$owner] = brokerWorkspace();
    [, $operator, $transaction, $commission] = (
        completedBrokerReportTransaction($owner)
    );

    $this->artisan('broker-reports:generate', [
        'transaction' => $transaction->getKey(),
        '--actor-email' => $operator->email,
        '--expected-transaction-event' => $transaction->current_event_id,
        '--expected-commission-event' => $commission->current_event_id,
        '--idempotency' => (string) Str::uuid(),
        '--locale' => 'fr',
        '--reason-code' => 'completed_transaction_report',
        '--evidence' => 'case:report-cli-001',
    ])->assertSuccessful();

    $report = BrokerReport::query()->sole();

    expect($report->locale)->toBe(SupportedLocale::French)
        ->and($report->status)->toBe(BrokerReportStatus::Available);
    Storage::disk('local')->assertExists($report->path);
});

test('stale expired disabled and cancellation offer boundaries fail closed', function () {
    [$owner] = brokerWorkspace();
    [$request, $operator, $searchEventId] = searchingBrokerRequest($owner);
    $present = app(PresentBrokerRequestOffer::class);
    config(['broker.commission_rate_basis_points' => 0]);

    expect(fn () => $present->execute(
        $request,
        $operator,
        $searchEventId,
        (string) Str::uuid(),
        'quote:invalid-commission-configuration',
        brokerOfferPayload(),
    ))->toThrow(ValidationException::class);
    expect($request->fresh()->current_event_id)->toBe($searchEventId)
        ->and($request->offers()->count())->toBe(0);

    config(['broker.commission_rate_basis_points' => 250]);
    $offer = $present->execute(
        $request,
        $operator,
        $searchEventId,
        (string) Str::uuid(),
        'quote:cancellation-boundary',
        brokerOfferPayload(),
    );

    expect(fn () => $present->execute(
        $request->fresh(),
        $operator,
        $offer->requestEvent->getKey(),
        (string) Str::uuid(),
        'quote:overflow-boundary',
        brokerOfferPayload([
            'quantity' => 2,
            'unit_price_minor' => 9007199254740991,
        ]),
    ))->toThrow(ValidationException::class);
    expect(fn () => $offer->offer->fresh()->forceFill([
        'unit_price_minor' => 1,
    ])->save())->toThrow(LogicException::class);
    expect(fn () => $offer->offerEvent->fresh()->update([
        'reason_code' => 'tampered',
    ]))->toThrow(LogicException::class);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.offers.accept', [
                'brokerRequest' => $request,
                'offer' => $offer->offer,
            ]),
            [
                'expected_request_event_id' => $searchEventId,
                'expected_offer_event_id' => $offer->offerEvent->getKey(),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expected_request_event_id');

    $offer->offer->forceFill([
        'valid_until' => now()->subSecond(),
    ]);
    DB::table('broker_request_offers')
        ->where('id', $offer->offer->getKey())
        ->update(['valid_until' => now()->subSecond()]);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.offers.accept', [
                'brokerRequest' => $request,
                'offer' => $offer->offer,
            ]),
            [
                'expected_request_event_id' => (
                    $offer->requestEvent->getKey()
                ),
                'expected_offer_event_id' => $offer->offerEvent->getKey(),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('broker_offer');

    DB::table('broker_request_offers')
        ->where('id', $offer->offer->getKey())
        ->update(['valid_until' => now()->addDay()]);
    $commissionAmount = $offer->offer->commission_amount_minor;
    DB::table('broker_request_offers')
        ->where('id', $offer->offer->getKey())
        ->update(['commission_amount_minor' => $commissionAmount + 1]);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.offers.accept', [
                'brokerRequest' => $request,
                'offer' => $offer->offer,
            ]),
            [
                'expected_request_event_id' => (
                    $offer->requestEvent->getKey()
                ),
                'expected_offer_event_id' => $offer->offerEvent->getKey(),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('broker_offer');
    expect(BrokerTransaction::query()->count())->toBe(0);

    DB::table('broker_request_offers')
        ->where('id', $offer->offer->getKey())
        ->update(['commission_amount_minor' => $commissionAmount]);
    config(['broker.offers_enabled' => false]);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.offers.accept', [
                'brokerRequest' => $request,
                'offer' => $offer->offer,
            ]),
            [
                'expected_request_event_id' => (
                    $offer->requestEvent->getKey()
                ),
                'expected_offer_event_id' => $offer->offerEvent->getKey(),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('broker_offer');

    $cancelled = $this->actingAs($owner)
        ->postJson(
            route('api.v1.broker-requests.cancel', $request),
            [
                'expected_current_event_id' => (
                    $offer->requestEvent->getKey()
                ),
                'idempotency_key' => (string) Str::uuid(),
            ],
        )
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($offer->offer->fresh()->status)->toBe(
        BrokerRequestOfferStatus::NotSelected,
    )->and($offer->offer->fresh()->resolved_at)->not->toBeNull()
        ->and($cancelled->json('data.current_event_id'))->not->toBeNull();
});
