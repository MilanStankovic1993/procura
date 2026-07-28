<?php

use App\Actions\Markets\SyncMarketReferenceData;
use App\Actions\Monitoring\DeliverAlertEmail;
use App\Actions\Monitoring\DeliverAlertTelegram;
use App\Actions\Monitoring\EvaluateSavedSearchMatch;
use App\Enums\Localization\SupportedLocale;
use App\Enums\Monitoring\NotificationChannel;
use App\Enums\Monitoring\NotificationEventType;
use App\Enums\Monitoring\SavedSearchMatchStatus;
use App\Enums\Monitoring\TelegramConnectionEventType;
use App\Enums\Monitoring\TelegramConnectionStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Subscriptions\FeatureCode;
use App\Jobs\Monitoring\MatchListingSnapshot;
use App\Jobs\Monitoring\MatchSavedSearch;
use App\Jobs\Monitoring\SendAlertEmail;
use App\Jobs\Monitoring\SendAlertTelegram;
use App\Models\Alert;
use App\Models\Listing;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlanFeature;
use App\Models\SavedSearch;
use App\Models\SavedSearchMatch;
use App\Models\SavedSearchVersion;
use App\Models\TelegramConnection;
use App\Models\TelegramConnectionEvent;
use App\Models\User;
use App\Monitoring\Telegram\Contracts\TelegramProvider;
use App\Monitoring\Telegram\Data\TelegramDeliveryResult;
use App\Monitoring\Telegram\Exceptions\TelegramProviderException;
use App\Monitoring\Telegram\SavedSearchMatchTelegramMessage;
use App\Notifications\Monitoring\SavedSearchMatchEmailNotification;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
    $this->seed(PlanSeeder::class);
    Queue::fake([
        MatchListingSnapshot::class,
        MatchSavedSearch::class,
        SendAlertEmail::class,
        SendAlertTelegram::class,
    ]);
});

function monitoringWorkspace(
    OrganizationRole $role = OrganizationRole::Owner,
): array {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $user,
        'role' => $role,
    ]);
    $user->update(['current_organization_id' => $organization->getKey()]);

    return [$user, $organization];
}

function savedSearchPayload(array $overrides = []): array
{
    return [
        'title' => 'Bosch drills in Germany',
        'active' => true,
        'minimum_price_minor' => 5000,
        'maximum_price_minor' => 25000,
        'price_currency_code' => 'EUR',
        'continent_code' => 'EU',
        'country_codes' => ['DE'],
        'include_cross_border' => false,
        'required_keywords' => ['Bosch'],
        'excluded_keywords' => ['broken'],
        'notification_channels' => ['in_app'],
        'reason_code' => 'saved_search_created',
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

function monitoringListingPayload(array $overrides = []): array
{
    return [
        'marketplace_source_key' => 'manual',
        'source_url' => 'https://market.example/listings/bosch-drill',
        'external_id' => 'bosch-drill',
        'marketplace_name' => 'Market Example',
        'title' => 'Bosch Professional cordless drill',
        'description' => 'Used drill with two batteries and a charger.',
        'asking_price_minor' => 12999,
        'currency_code' => 'EUR',
        'seller_information' => 'Private seller',
        'location' => 'Berlin',
        'source_country_code' => 'DE',
        'target_country_code' => 'DE',
        'status' => 'active',
        'notes' => null,
        ...$overrides,
    ];
}

function configureTelegramMonitoring(): void
{
    config([
        'monitoring.telegram.bot_token' => '123456:local-test-secret',
        'monitoring.telegram.bot_username' => 'ProcuraTestBot',
        'monitoring.telegram.webhook_secret' => 'local-webhook-secret',
        'monitoring.telegram.identity_hash_key' => (
            'local-telegram-identity-hash-key'
        ),
    ]);
    PlanFeature::query()
        ->where('feature_code', FeatureCode::TelegramNotifications)
        ->update(['is_enabled' => true]);
}

function connectTelegramForTest(
    $test,
    User $user,
    int $updateId = 1001,
): TelegramConnection {
    $link = $test->actingAs($user)
        ->postJson(route('api.v1.me.telegram-connection.link'))
        ->assertOk()
        ->json('data.link_url');
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    $test->postJson(
        route('api.v1.integrations.telegram.webhook'),
        [
            'update_id' => $updateId,
            'message' => [
                'text' => '/start '.$query['start'],
                'chat' => ['id' => 998877, 'type' => 'private'],
                'from' => ['id' => 998877, 'username' => 'procura_qa'],
            ],
        ],
        ['X-Telegram-Bot-Api-Secret-Token' => 'local-webhook-secret'],
    )->assertNoContent();

    return TelegramConnection::query()
        ->where('user_id', $user->getKey())
        ->connected()
        ->sole();
}

test('saved searches enforce immutable versions idempotency plan limits and tenant roles', function () {
    [$owner, $organization] = monitoringWorkspace();
    $payload = savedSearchPayload();

    $created = $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('data.title', 'Bosch drills in Germany')
        ->assertJsonPath('data.current_version.sequence', 1)
        ->assertJsonPath('meta.created', true);
    $searchId = $created->json('data.id');
    $versionId = $created->json('data.current_version_id');

    $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.store'), $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $searchId)
        ->assertJsonPath('meta.created', false);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.saved-searches.store'),
            savedSearchPayload(['title' => 'Second free-plan search']),
        )
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.saved_search.0.code',
            'saved_search_limit_reached',
        );

    $updatedPayload = savedSearchPayload([
        'title' => 'Bosch tools in Germany',
        'reason_code' => 'criteria_refined',
        'expected_current_version_id' => $versionId,
    ]);
    $updated = $this->actingAs($owner)
        ->putJson(
            route('api.v1.saved-searches.update', $searchId),
            $updatedPayload,
        )
        ->assertOk()
        ->assertJsonPath('data.version_sequence', 2)
        ->assertJsonPath('data.current_version.sequence', 2)
        ->assertJsonPath('meta.created', true);
    $secondVersionId = $updated->json('data.current_version_id');

    $this->actingAs($owner)
        ->putJson(
            route('api.v1.saved-searches.update', $searchId),
            savedSearchPayload([
                'reason_code' => 'stale_update',
                'expected_current_version_id' => $versionId,
            ]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expected_current_version_id');

    expect(SavedSearchVersion::query()->count())->toBe(2);
    $this->actingAs($owner)
        ->getJson(route('api.v1.saved-searches.show', $searchId))
        ->assertOk()
        ->assertJsonCount(2, 'data.versions');

    $viewer = User::factory()->create();
    OrganizationMembership::factory()->viewer()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);

    $this->actingAs($viewer)
        ->getJson(route('api.v1.saved-searches.show', $searchId))
        ->assertOk();
    $this->actingAs($viewer)
        ->putJson(
            route('api.v1.saved-searches.update', $searchId),
            savedSearchPayload([
                'reason_code' => 'viewer_update',
                'expected_current_version_id' => $secondVersionId,
            ]),
        )
        ->assertForbidden();

    [$outsider] = monitoringWorkspace();
    $this->actingAs($outsider)
        ->getJson(route('api.v1.saved-searches.show', $searchId))
        ->assertNotFound();

    $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.archive', $searchId), [
            'expected_current_version_id' => $secondVersionId,
            'reason_code' => 'no_longer_needed',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonPath('data.archived', true)
        ->assertJsonPath('data.active', false);

    $this->actingAs($owner)
        ->postJson(
            route('api.v1.saved-searches.store'),
            savedSearchPayload(['title' => 'Replacement free-plan search']),
        )
        ->assertCreated();

    expect(SavedSearch::query()
        ->where('organization_id', $organization->getKey())
        ->count())->toBe(2);
});

test('a deterministic match creates one recipient alert with an append-only state ledger', function () {
    [$owner, $organization] = monitoringWorkspace();
    $searchId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.saved-searches.store'),
            savedSearchPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $listingId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.listings.store'),
            monitoringListingPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $search = SavedSearch::query()->findOrFail($searchId);
    $version = $search->currentVersion()->firstOrFail();
    $snapshot = Listing::query()
        ->findOrFail($listingId)
        ->snapshots()
        ->firstOrFail();
    $evaluator = app(EvaluateSavedSearchMatch::class);

    $firstMatch = $evaluator->evaluate($version, $snapshot);
    $replayedMatch = $evaluator->evaluate($version, $snapshot);

    expect($firstMatch?->status)->toBe(SavedSearchMatchStatus::Matched)
        ->and($replayedMatch?->is($firstMatch))->toBeTrue()
        ->and(SavedSearchMatch::query()->count())->toBe(1)
        ->and(Alert::query()->count())->toBe(1)
        ->and(NotificationLog::query()->count())->toBe(1);

    $notification = $this->actingAs($owner)
        ->getJson(route('api.v1.notifications.index', ['state' => 'unread']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.state', 'delivered')
        ->assertJsonPath('data.0.listing.id', $listingId);
    $alertId = $notification->json('data.0.id');
    $deliveredLogId = $notification->json('data.0.current_log_id');
    $readKey = (string) Str::uuid();

    $read = $this->actingAs($owner)
        ->postJson(route('api.v1.notifications.state.store', $alertId), [
            'event_type' => NotificationEventType::Read->value,
            'expected_current_log_id' => $deliveredLogId,
            'idempotency_key' => $readKey,
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'read')
        ->assertJsonPath('data.read', true);
    $readLogId = $read->json('data.current_log_id');

    $this->actingAs($owner)
        ->postJson(route('api.v1.notifications.state.store', $alertId), [
            'event_type' => NotificationEventType::Read->value,
            'expected_current_log_id' => $deliveredLogId,
            'idempotency_key' => $readKey,
        ])
        ->assertOk()
        ->assertJsonPath('data.current_log_id', $readLogId);

    $this->actingAs($owner)
        ->getJson(route('api.v1.notifications.index', ['state' => 'unread']))
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.unread_count', 0);

    $this->actingAs($owner)
        ->postJson(route('api.v1.notifications.state.store', $alertId), [
            'event_type' => NotificationEventType::Archived->value,
            'expected_current_log_id' => $readLogId,
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'archived');

    $this->actingAs($owner)
        ->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect(NotificationLog::query()->count())->toBe(3);

    $otherMember = User::factory()->create();
    OrganizationMembership::factory()->viewer()->create([
        'organization_id' => $organization,
        'user_id' => $otherMember,
    ]);
    $otherMember->update([
        'current_organization_id' => $organization->getKey(),
    ]);
    $this->actingAs($otherMember)
        ->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('criteria requiring unavailable financial or geospatial evidence never produce an alert', function () {
    [$owner] = monitoringWorkspace();
    $searchId = $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.store'), savedSearchPayload([
            'city' => 'Berlin',
            'radius_km' => 25,
            'minimum_profit_minor' => 5000,
            'profit_currency_code' => 'EUR',
        ]))
        ->assertCreated()
        ->json('data.id');
    $listingId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.listings.store'),
            monitoringListingPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $search = SavedSearch::query()->findOrFail($searchId);
    $snapshot = Listing::query()
        ->findOrFail($listingId)
        ->snapshots()
        ->firstOrFail();

    $match = app(EvaluateSavedSearchMatch::class)->evaluate(
        $search->currentVersion()->firstOrFail(),
        $snapshot,
    );

    expect($match?->status)
        ->toBe(SavedSearchMatchStatus::InsufficientEvidence)
        ->and($match?->unknown_criteria)
        ->toContain(
            'geospatial_coordinates_unavailable',
            'profit_estimate_unavailable',
        )
        ->and(Alert::query()->count())->toBe(0);
});

test('saved-search input rejects unsupported delivery channels and incoherent price ranges', function () {
    [$owner] = monitoringWorkspace();

    $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.store'), savedSearchPayload([
            'minimum_price_minor' => 30000,
            'maximum_price_minor' => 10000,
            'notification_channels' => ['sms'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'maximum_price_minor',
            'notification_channels',
            'notification_channels.0',
        ]);
});

test('email alerts are entitled queued localized delivered and replay safe', function () {
    [$owner] = monitoringWorkspace();
    $searchId = $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.store'), savedSearchPayload([
            'notification_channels' => ['in_app', 'email'],
        ]))
        ->assertCreated()
        ->assertJsonPath(
            'data.current_version.notification_channels',
            ['email', 'in_app'],
        )
        ->json('data.id');
    $listingId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.listings.store'),
            monitoringListingPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $search = SavedSearch::query()->findOrFail($searchId);
    $snapshot = Listing::query()
        ->findOrFail($listingId)
        ->snapshots()
        ->firstOrFail();

    app(EvaluateSavedSearchMatch::class)->evaluate(
        $search->currentVersion()->firstOrFail(),
        $snapshot,
    );

    $alert = Alert::query()->sole();
    Queue::assertPushed(
        SendAlertEmail::class,
        fn (SendAlertEmail $job): bool => $job->alertId === $alert->getKey(),
    );
    expect(NotificationLog::query()
        ->where('channel', NotificationChannel::Email)
        ->sole()
        ->event_type)->toBe(NotificationEventType::Queued);

    Notification::fake();
    $delivery = app(DeliverAlertEmail::class);
    $delivery->deliver($alert->getKey(), 1, 4);
    $delivery->deliver($alert->getKey(), 1, 4);

    Notification::assertSentToTimes(
        $owner,
        SavedSearchMatchEmailNotification::class,
        1,
    );
    expect(NotificationLog::query()
        ->where('channel', NotificationChannel::Email)
        ->orderBy('sequence')
        ->get()
        ->map(fn (NotificationLog $log): string => $log->event_type->value)
        ->all())->toBe(['queued', 'attempting', 'delivered']);

    $this->actingAs($owner)
        ->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonPath('data.0.delivery.email.state', 'delivered')
        ->assertJsonPath('data.0.delivery.email.attempt', 1);

    foreach (SupportedLocale::cases() as $locale) {
        app()->setLocale($locale->laravelLocale());
        $message = (new SavedSearchMatchEmailNotification($alert))
            ->toMail($owner);

        expect($message->subject)->not->toContain('monitoring.')
            ->and($message->actionUrl)->toContain(
                "/app/buy/{$alert->listing_id}",
            );
    }
});

test('email delivery failures append retry evidence and terminal exhaustion', function () {
    [$owner] = monitoringWorkspace();
    $searchId = $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.store'), savedSearchPayload([
            'notification_channels' => ['in_app', 'email'],
        ]))
        ->assertCreated()
        ->json('data.id');
    $listingId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.listings.store'),
            monitoringListingPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $search = SavedSearch::query()->findOrFail($searchId);
    $snapshot = Listing::query()
        ->findOrFail($listingId)
        ->snapshots()
        ->firstOrFail();
    app(EvaluateSavedSearchMatch::class)->evaluate(
        $search->currentVersion()->firstOrFail(),
        $snapshot,
    );
    $alert = Alert::query()->sole();
    Notification::shouldReceive('sendNow')
        ->once()
        ->andThrow(new RuntimeException('Mail provider unavailable.'));
    $delivery = app(DeliverAlertEmail::class);

    expect(fn () => $delivery->deliver($alert->getKey(), 1, 1))
        ->toThrow(RuntimeException::class, 'Mail provider unavailable.');

    $failed = NotificationLog::query()
        ->where('channel', NotificationChannel::Email)
        ->orderByDesc('sequence')
        ->firstOrFail();

    expect($failed->event_type)->toBe(NotificationEventType::Failed)
        ->and($failed->payload['delivery']['attempt'])->toBe(1)
        ->and($failed->payload['delivery']['will_retry'])->toBeFalse()
        ->and($failed->payload['delivery']['exception_summary'])
        ->toBe('Mail provider unavailable.');

    $delivery->markExhausted(
        $alert->getKey(),
        new RuntimeException('Mail provider unavailable.'),
    );

    expect(NotificationLog::query()
        ->where('channel', NotificationChannel::Email)
        ->orderByDesc('sequence')
        ->firstOrFail()
        ->event_type)->toBe(NotificationEventType::Exhausted);
});

test('email opt in is plan authoritative and orphaned queued deliveries are recoverable', function () {
    [$owner] = monitoringWorkspace();
    $searchId = $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.store'), savedSearchPayload([
            'notification_channels' => ['in_app', 'email'],
        ]))
        ->assertCreated()
        ->json('data.id');
    $listingId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.listings.store'),
            monitoringListingPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $search = SavedSearch::query()->findOrFail($searchId);
    $snapshot = Listing::query()
        ->findOrFail($listingId)
        ->snapshots()
        ->firstOrFail();
    app(EvaluateSavedSearchMatch::class)->evaluate(
        $search->currentVersion()->firstOrFail(),
        $snapshot,
    );
    $queued = NotificationLog::query()
        ->where('channel', NotificationChannel::Email)
        ->sole();
    DB::table('notification_logs')
        ->where('id', $queued->getKey())
        ->update(['occurred_at' => now()->subMinutes(10)]);
    Queue::fake([SendAlertEmail::class]);

    $this->artisan('notifications:recover-email-deliveries', ['--limit' => 10])
        ->expectsOutput('Redispatched 1 email delivery records.')
        ->assertSuccessful();
    Queue::assertPushed(
        SendAlertEmail::class,
        fn (SendAlertEmail $job): bool => $job->alertId === $queued->alert_id,
    );

    PlanFeature::query()
        ->where('feature_code', FeatureCode::EmailNotifications)
        ->update(['is_enabled' => false]);
    [$otherOwner] = monitoringWorkspace();
    $this->actingAs($otherOwner)
        ->postJson(route('api.v1.saved-searches.store'), savedSearchPayload([
            'notification_channels' => ['in_app', 'email'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notification_channels');
});

test('Telegram connection linking is entitled encrypted replay safe and revocable', function () {
    configureTelegramMonitoring();
    [$owner] = monitoringWorkspace();

    $this->actingAs($owner)
        ->getJson(route('api.v1.me.telegram-connection.show'))
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.entitled', true)
        ->assertJsonPath('data.status', 'disconnected');

    $linkResponse = $this->actingAs($owner)
        ->postJson(route('api.v1.me.telegram-connection.link'))
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');
    $linkUrl = $linkResponse->json('data.link_url');
    parse_str((string) parse_url($linkUrl, PHP_URL_QUERY), $query);
    $token = $query['start'];
    $connection = TelegramConnection::query()->sole();
    $raw = DB::table('telegram_connections')
        ->where('id', $connection->getKey())
        ->first();

    expect($token)->toHaveLength(43)
        ->and($connection->challenge_token)->toBe($token)
        ->and($connection->challenge_token_hash)->toBe(
            hash('sha256', $token),
        )
        ->and($raw->challenge_token)->not->toBe($token);

    $this->postJson(
        route('api.v1.integrations.telegram.webhook'),
        ['update_id' => 1],
        ['X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret'],
    )->assertForbidden();

    $payload = [
        'update_id' => 501,
        'message' => [
            'text' => '/start '.$token,
            'chat' => ['id' => 998877, 'type' => 'private'],
            'from' => ['id' => 998877, 'username' => 'procura_qa'],
        ],
    ];
    $headers = [
        'X-Telegram-Bot-Api-Secret-Token' => 'local-webhook-secret',
    ];
    $this->postJson(
        route('api.v1.integrations.telegram.webhook'),
        $payload,
        $headers,
    )->assertNoContent();
    $this->postJson(
        route('api.v1.integrations.telegram.webhook'),
        $payload,
        $headers,
    )->assertNoContent();

    $connection->refresh();
    $raw = DB::table('telegram_connections')
        ->where('id', $connection->getKey())
        ->first();
    expect($connection->status)
        ->toBe(TelegramConnectionStatus::Connected)
        ->and($connection->telegram_user_id)->toBe('998877')
        ->and($connection->chat_id)->toBe('998877')
        ->and($connection->telegram_user_id_hash)->toBe(
            hash_hmac(
                'sha256',
                '998877',
                'local-telegram-identity-hash-key',
            ),
        )
        ->and($connection->telegram_user_id_hash)->not->toBe(
            hash('sha256', '998877'),
        )
        ->and($connection->challenge_token)->toBeNull()
        ->and($raw->telegram_user_id)->not->toBe('998877')
        ->and($raw->chat_id)->not->toBe('998877')
        ->and(
            TelegramConnectionEvent::query()
                ->orderBy('sequence')
                ->pluck('event_type')
                ->all(),
        )->toBe([
            TelegramConnectionEventType::Pending,
            TelegramConnectionEventType::Connected,
        ]);

    $this->actingAs($owner)
        ->getJson(route('api.v1.me.telegram-connection.show'))
        ->assertOk()
        ->assertJsonPath('data.status', 'connected')
        ->assertJsonPath('data.can_enable_delivery', true)
        ->assertJsonMissingPath('data.chat_id');

    $this->actingAs($owner)
        ->deleteJson(route('api.v1.me.telegram-connection.destroy'))
        ->assertOk()
        ->assertJsonPath('data.status', 'disconnected');

    expect($connection->refresh()->status)
        ->toBe(TelegramConnectionStatus::Revoked)
        ->and($connection->telegram_user_id)->toBeNull()
        ->and($connection->telegram_user_id_hash)->toBeNull()
        ->and($connection->chat_id)->toBeNull()
        ->and($connection->chat_id_hash)->toBeNull()
        ->and(
            TelegramConnectionEvent::query()
                ->orderBy('sequence')
                ->pluck('event_type')
                ->all(),
        )->toBe([
            TelegramConnectionEventType::Pending,
            TelegramConnectionEventType::Connected,
            TelegramConnectionEventType::Revoked,
        ]);
});

test('one Telegram identity has one connected owner and can be reassigned after revocation', function () {
    configureTelegramMonitoring();
    [$firstOwner] = monitoringWorkspace();
    [$secondOwner] = monitoringWorkspace();
    connectTelegramForTest($this, $firstOwner);

    $secondLink = $this->actingAs($secondOwner)
        ->postJson(route('api.v1.me.telegram-connection.link'))
        ->assertOk()
        ->json('data.link_url');
    parse_str((string) parse_url($secondLink, PHP_URL_QUERY), $query);
    $payload = [
        'update_id' => 2001,
        'message' => [
            'text' => '/start '.$query['start'],
            'chat' => ['id' => 998877, 'type' => 'private'],
            'from' => ['id' => 998877, 'username' => 'procura_qa'],
        ],
    ];
    $headers = [
        'X-Telegram-Bot-Api-Secret-Token' => 'local-webhook-secret',
    ];

    $this->postJson(
        route('api.v1.integrations.telegram.webhook'),
        $payload,
        $headers,
    )->assertNoContent();

    expect(TelegramConnection::query()->connected()->count())->toBe(1)
        ->and(
            TelegramConnection::query()
                ->where('user_id', $secondOwner->getKey())
                ->sole()
                ->status,
        )->toBe(TelegramConnectionStatus::Pending);

    $this->actingAs($firstOwner)
        ->deleteJson(route('api.v1.me.telegram-connection.destroy'))
        ->assertOk();
    $payload['update_id'] = 2002;
    $this->postJson(
        route('api.v1.integrations.telegram.webhook'),
        $payload,
        $headers,
    )->assertNoContent();

    $secondConnection = TelegramConnection::query()
        ->where('user_id', $secondOwner->getKey())
        ->sole();
    expect(TelegramConnection::query()->connected()->count())->toBe(1)
        ->and($secondConnection->status)
        ->toBe(TelegramConnectionStatus::Connected)
        ->and($secondConnection->telegram_user_id_hash)->not->toBeNull();
});

test('Telegram connection creation enforces plan and provider boundaries', function () {
    [$owner] = monitoringWorkspace();
    config([
        'monitoring.telegram.bot_token' => '123456:local-test-secret',
        'monitoring.telegram.bot_username' => 'ProcuraTestBot',
        'monitoring.telegram.webhook_secret' => 'local-webhook-secret',
        'monitoring.telegram.identity_hash_key' => (
            'local-telegram-identity-hash-key'
        ),
    ]);

    $this->actingAs($owner)
        ->getJson(route('api.v1.me.telegram-connection.show'))
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.entitled', false);
    $this->actingAs($owner)
        ->postJson(route('api.v1.me.telegram-connection.link'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('telegram');

    PlanFeature::query()
        ->where('feature_code', FeatureCode::TelegramNotifications)
        ->update(['is_enabled' => true]);
    config(['monitoring.telegram.bot_token' => null]);

    $this->actingAs($owner)
        ->postJson(route('api.v1.me.telegram-connection.link'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('telegram');
    expect(TelegramConnection::query()->count())->toBe(0);
});

test('expired Telegram challenges append one terminal event and remain replay safe', function () {
    configureTelegramMonitoring();
    [$owner] = monitoringWorkspace();

    $this->actingAs($owner)
        ->postJson(route('api.v1.me.telegram-connection.link'))
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');
    $connection = TelegramConnection::query()->sole();
    DB::table('telegram_connections')
        ->where('id', $connection->getKey())
        ->update(['challenge_expires_at' => now()->subMinute()]);

    $this->artisan(
        'notifications:expire-telegram-connections',
        ['--limit' => 10],
    )
        ->expectsOutput('Expired 1 Telegram connection challenges.')
        ->assertSuccessful();
    $this->artisan(
        'notifications:expire-telegram-connections',
        ['--limit' => 10],
    )
        ->expectsOutput('Expired 0 Telegram connection challenges.')
        ->assertSuccessful();

    expect($connection->refresh()->status)
        ->toBe(TelegramConnectionStatus::Expired)
        ->and($connection->challenge_token)->toBeNull()
        ->and(
            TelegramConnectionEvent::query()
                ->orderBy('sequence')
                ->pluck('event_type')
                ->all(),
        )->toBe([
            TelegramConnectionEventType::Pending,
            TelegramConnectionEventType::Expired,
        ]);
});

test('Telegram alerts are queued localized delivered and replay safe', function () {
    configureTelegramMonitoring();
    [$owner] = monitoringWorkspace();
    connectTelegramForTest($this, $owner);
    $provider = new class implements TelegramProvider
    {
        /** @var list<array<string, string>> */
        public array $messages = [];

        public function sendMessage(
            TelegramConnection $connection,
            string $message,
            string $actionLabel,
            string $actionUrl,
        ): TelegramDeliveryResult {
            $this->messages[] = compact(
                'message',
                'actionLabel',
                'actionUrl',
            );

            return new TelegramDeliveryResult('telegram-message-77');
        }
    };
    app()->instance(TelegramProvider::class, $provider);
    $searchId = $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.store'), savedSearchPayload([
            'notification_channels' => ['in_app', 'telegram'],
        ]))
        ->assertCreated()
        ->assertJsonPath(
            'data.current_version.notification_channels',
            ['in_app', 'telegram'],
        )
        ->json('data.id');
    $listingId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.listings.store'),
            monitoringListingPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $search = SavedSearch::query()->findOrFail($searchId);
    $snapshot = Listing::query()
        ->findOrFail($listingId)
        ->snapshots()
        ->firstOrFail();
    app(EvaluateSavedSearchMatch::class)->evaluate(
        $search->currentVersion()->firstOrFail(),
        $snapshot,
    );
    $alert = Alert::query()->sole();

    Queue::assertPushed(
        SendAlertTelegram::class,
        fn (SendAlertTelegram $job): bool => (
            $job->alertId === $alert->getKey()
        ),
    );
    $delivery = app(DeliverAlertTelegram::class);
    $delivery->deliver($alert->getKey(), 1, 4);
    $delivery->deliver($alert->getKey(), 1, 4);

    expect($provider->messages)->toHaveCount(1)
        ->and($provider->messages[0]['actionUrl'])->toContain(
            "/app/buy/{$alert->listing_id}",
        )
        ->and(NotificationLog::query()
            ->where('channel', NotificationChannel::Telegram)
            ->orderBy('sequence')
            ->get()
            ->map(
                fn (NotificationLog $log): string => (
                    $log->event_type->value
                ),
            )
            ->all())->toBe(['queued', 'attempting', 'delivered'])
        ->and(NotificationLog::query()
            ->where('channel', NotificationChannel::Telegram)
            ->latest('sequence')
            ->firstOrFail()
            ->payload['delivery']['provider_message_id'])
        ->toBe('telegram-message-77');

    $this->actingAs($owner)
        ->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonPath('data.0.delivery.telegram.state', 'delivered')
        ->assertJsonPath('data.0.delivery.telegram.attempt', 1);

    foreach (SupportedLocale::cases() as $locale) {
        $content = app(SavedSearchMatchTelegramMessage::class)->build(
            $alert,
            $locale->laravelLocale(),
        );

        expect($content['message'])->not->toContain('monitoring.telegram')
            ->and($content['action_url'])->toContain(
                "/app/buy/{$alert->listing_id}",
            );
    }
});

test('Telegram delivery rechecks revocation without exposing secrets', function () {
    configureTelegramMonitoring();
    [$owner] = monitoringWorkspace();
    $connection = connectTelegramForTest($this, $owner);
    $searchId = $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.store'), savedSearchPayload([
            'notification_channels' => ['in_app', 'telegram'],
        ]))
        ->assertCreated()
        ->json('data.id');
    $listingId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.listings.store'),
            monitoringListingPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $search = SavedSearch::query()->findOrFail($searchId);
    $snapshot = Listing::query()
        ->findOrFail($listingId)
        ->snapshots()
        ->firstOrFail();
    app(EvaluateSavedSearchMatch::class)->evaluate(
        $search->currentVersion()->firstOrFail(),
        $snapshot,
    );
    $alert = Alert::query()->sole();

    $this->actingAs($owner)
        ->deleteJson(route('api.v1.me.telegram-connection.destroy'))
        ->assertOk();
    $provider = new class implements TelegramProvider
    {
        public int $calls = 0;

        public function sendMessage(
            TelegramConnection $connection,
            string $message,
            string $actionLabel,
            string $actionUrl,
        ): TelegramDeliveryResult {
            $this->calls++;

            throw new TelegramProviderException(
                'provider_should_not_be_called',
            );
        }
    };
    app()->instance(TelegramProvider::class, $provider);
    app(DeliverAlertTelegram::class)->deliver(
        $alert->getKey(),
        1,
        4,
    );
    $current = NotificationLog::query()
        ->where('channel', NotificationChannel::Telegram)
        ->latest('sequence')
        ->firstOrFail();

    expect($provider->calls)->toBe(0)
        ->and($connection->refresh()->status)
        ->toBe(TelegramConnectionStatus::Revoked)
        ->and($current->event_type)
        ->toBe(NotificationEventType::Suppressed)
        ->and($current->payload['delivery']['reason_code'])
        ->toBe('telegram_connection_revoked')
        ->and(json_encode($current->payload, JSON_THROW_ON_ERROR))
        ->not->toContain(
            '123456:local-test-secret',
            '998877',
        );
});

test('Telegram provider failures are sanitized exhausted and recoverable', function () {
    configureTelegramMonitoring();
    [$owner] = monitoringWorkspace();
    connectTelegramForTest($this, $owner);
    $searchId = $this->actingAs($owner)
        ->postJson(route('api.v1.saved-searches.store'), savedSearchPayload([
            'notification_channels' => ['in_app', 'telegram'],
        ]))
        ->assertCreated()
        ->json('data.id');
    $listingId = $this->actingAs($owner)
        ->postJson(
            route('api.v1.listings.store'),
            monitoringListingPayload(),
        )
        ->assertCreated()
        ->json('data.id');
    $search = SavedSearch::query()->findOrFail($searchId);
    $snapshot = Listing::query()
        ->findOrFail($listingId)
        ->snapshots()
        ->firstOrFail();
    app(EvaluateSavedSearchMatch::class)->evaluate(
        $search->currentVersion()->firstOrFail(),
        $snapshot,
    );
    $alert = Alert::query()->sole();
    $queued = NotificationLog::query()
        ->where('channel', NotificationChannel::Telegram)
        ->sole();
    $provider = new class implements TelegramProvider
    {
        public function sendMessage(
            TelegramConnection $connection,
            string $message,
            string $actionLabel,
            string $actionUrl,
        ): TelegramDeliveryResult {
            throw new TelegramProviderException(
                'telegram_provider_rejected',
                429,
            );
        }
    };
    app()->instance(TelegramProvider::class, $provider);
    $delivery = app(DeliverAlertTelegram::class);

    expect(fn () => $delivery->deliver($alert->getKey(), 1, 1))
        ->toThrow(TelegramProviderException::class);
    $failed = NotificationLog::query()
        ->where('channel', NotificationChannel::Telegram)
        ->latest('sequence')
        ->firstOrFail();
    expect($failed->event_type)->toBe(NotificationEventType::Failed)
        ->and($failed->payload['delivery']['exception_summary'])
        ->toBe('telegram_provider_rejected')
        ->and(json_encode($failed->payload, JSON_THROW_ON_ERROR))
        ->not->toContain('123456:local-test-secret', '998877');

    $delivery->markExhausted(
        $alert->getKey(),
        new TelegramProviderException('telegram_provider_rejected', 429),
    );
    expect(NotificationLog::query()
        ->where('channel', NotificationChannel::Telegram)
        ->latest('sequence')
        ->firstOrFail()
        ->event_type)
        ->toBe(NotificationEventType::Exhausted);

    NotificationLog::query()->forceCreate([
        'organization_id' => $queued->organization_id,
        'alert_id' => $queued->alert_id,
        'recipient_user_id' => $queued->recipient_user_id,
        'channel' => NotificationChannel::Telegram,
        'event_type' => NotificationEventType::Queued,
        'sequence' => 99,
        'payload' => $queued->payload,
        'occurred_at' => now()->subMinutes(10),
        'created_at' => now()->subMinutes(10),
    ]);
    Queue::fake([SendAlertTelegram::class]);
    $this->artisan(
        'notifications:recover-telegram-deliveries',
        ['--limit' => 10],
    )
        ->expectsOutput('Redispatched 1 Telegram delivery records.')
        ->assertSuccessful();
    Queue::assertPushed(
        SendAlertTelegram::class,
        fn (SendAlertTelegram $job): bool => (
            $job->alertId === $alert->getKey()
        ),
    );
});

test('Telegram Bot API adapter registers the webhook and sends bounded requests', function () {
    configureTelegramMonitoring();
    [$owner] = monitoringWorkspace();
    $connection = connectTelegramForTest($this, $owner);
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/setWebhook')) {
            return Http::response(['ok' => true, 'result' => true]);
        }

        return Http::response([
            'ok' => true,
            'result' => ['message_id' => 901],
        ]);
    });

    $this->artisan('notifications:configure-telegram-webhook', [
        'url' => (
            'https://procura.example/api/v1/integrations/telegram/webhook'
        ),
    ])
        ->expectsOutput('Telegram webhook registered successfully.')
        ->assertSuccessful();
    $result = app(TelegramProvider::class)->sendMessage(
        $connection,
        'Bounded Procura test message',
        'Review listing',
        'https://procura.example/app/buy/01JLISTING',
    );

    expect($result->providerMessageId)->toBe('901');
    Http::assertSentCount(2);
    Http::assertSent(
        fn ($request): bool => (
            str_ends_with($request->url(), '/setWebhook')
            && $request['secret_token'] === 'local-webhook-secret'
            && $request['allowed_updates'] === ['message']
        ),
    );
    Http::assertSent(
        fn ($request): bool => (
            str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '998877'
            && $request['text'] === 'Bounded Procura test message'
            && $request['disable_web_page_preview'] === true
        ),
    );
});
