<?php

use App\Jobs\Operations\RecordQueueHeartbeat;
use App\Operations\OperationalReadiness;
use App\Operations\QueueHeartbeatStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('operations.readiness.cache_store', 'array');
    config()->set(
        'operations.readiness.queue_heartbeats.queues',
        ['analyses', 'connectors', 'notifications', 'default'],
    );
    config()->set(
        'operations.readiness.queue_heartbeats.maximum_age_seconds',
        180,
    );
    config()->set(
        'operations.readiness.queue_heartbeats.maximum_latency_seconds',
        120,
    );
    config()->set(
        'operations.readiness.queue_heartbeats.ttl_seconds',
        900,
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('queue heartbeat dispatch is disabled by default', function () {
    Queue::fake();

    config()->set(
        'operations.readiness.queue_heartbeats.enabled',
        false,
    );

    $this->artisan('operations:dispatch-queue-heartbeats')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('one heartbeat is dispatched to every isolated worker queue', function () {
    Queue::fake();

    config()->set(
        'operations.readiness.queue_heartbeats.enabled',
        true,
    );

    $this->artisan('operations:dispatch-queue-heartbeats')
        ->expectsOutputToContain('Dispatched 4')
        ->assertSuccessful();

    Queue::assertPushed(RecordQueueHeartbeat::class, 4);

    foreach (['analyses', 'connectors', 'notifications', 'default'] as $queue) {
        Queue::assertPushedOn(
            $queue,
            RecordQueueHeartbeat::class,
            fn (RecordQueueHeartbeat $job): bool => (
                $job->queueName === $queue
                && CarbonImmutable::parse($job->dispatchedAt)
                    ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            ),
        );
    }
});

test('fresh processed heartbeats make all configured queues ready', function () {
    config()->set(
        'operations.readiness.queue_heartbeats.enabled',
        true,
    );
    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-07-28T12:00:00Z'),
    );

    $heartbeats = app(QueueHeartbeatStore::class);

    foreach (['analyses', 'connectors', 'notifications', 'default'] as $queue) {
        (new RecordQueueHeartbeat(
            queueName: $queue,
            dispatchedAt: CarbonImmutable::now('UTC')
                ->subSecond()
                ->toIso8601String(),
        ))->handle($heartbeats);
    }

    $this->getJson(route('api.v1.health'))
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.checks.database', 'ok')
        ->assertJsonPath('data.checks.cache', 'ok')
        ->assertJsonPath('data.checks.queues', 'ok')
        ->assertJsonMissingPath('data.queue_heartbeats');

    expect(
        array_keys(
            app(OperationalReadiness::class)
                ->inspect(requireQueueHeartbeats: true)
                ->queueChecks,
        ),
    )->toBe(['analyses', 'connectors', 'notifications', 'default']);

    $exitCode = Artisan::call('operations:readiness', [
        '--require-queue-heartbeats' => true,
        '--json' => true,
    ]);
    $payload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(0)
        ->and(array_keys($payload['queue_heartbeats']))
        ->toBe(['analyses', 'connectors', 'notifications', 'default']);
});

test('missing or stale queue heartbeats fail readiness without public topology details', function () {
    config()->set(
        'operations.readiness.queue_heartbeats.enabled',
        true,
    );
    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-07-28T12:00:00Z'),
    );

    $response = $this->getJson(route('api.v1.health'))
        ->assertServiceUnavailable()
        ->assertJsonPath('data.status', 'unavailable')
        ->assertJsonPath('data.checks.queues', 'unavailable')
        ->assertJsonMissingPath('data.queue_heartbeats');

    expect($response->getContent())
        ->not->toContain('analyses')
        ->not->toContain('connectors')
        ->not->toContain('notifications');

    $heartbeats = app(QueueHeartbeatStore::class);

    foreach (['analyses', 'connectors', 'notifications', 'default'] as $queue) {
        $heartbeats->record(
            queue: $queue,
            dispatchedAt: CarbonImmutable::now('UTC')->subSecond(),
        );
    }

    CarbonImmutable::setTestNow(
        CarbonImmutable::now('UTC')->addSeconds(181),
    );

    $this->getJson(route('api.v1.health'))
        ->assertServiceUnavailable()
        ->assertJsonPath('data.checks.queues', 'unavailable');

    $this->artisan('operations:readiness', [
        '--require-queue-heartbeats' => true,
        '--json' => true,
    ])
        ->expectsOutputToContain('"status":"stale"')
        ->assertFailed();
});

test('a newly processed but excessively delayed heartbeat remains unavailable', function () {
    config()->set(
        'operations.readiness.queue_heartbeats.enabled',
        true,
    );
    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-07-28T12:00:00Z'),
    );

    $heartbeats = app(QueueHeartbeatStore::class);

    foreach (['analyses', 'connectors', 'notifications', 'default'] as $queue) {
        $heartbeats->record(
            queue: $queue,
            dispatchedAt: CarbonImmutable::now('UTC')->subSeconds(121),
        );
    }

    $this->getJson(route('api.v1.health'))
        ->assertServiceUnavailable()
        ->assertJsonPath('data.checks.queues', 'unavailable');
});

test('an older delayed job cannot overwrite a newer queue heartbeat', function () {
    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-07-28T12:00:00Z'),
    );

    $heartbeats = app(QueueHeartbeatStore::class);
    $newerDispatch = CarbonImmutable::now('UTC')->subSecond();

    expect($heartbeats->record(
        queue: 'analyses',
        dispatchedAt: $newerDispatch,
    ))->toBeTrue();

    expect($heartbeats->record(
        queue: 'analyses',
        dispatchedAt: CarbonImmutable::now('UTC')->subSeconds(30),
        processedAt: CarbonImmutable::now('UTC')->addSecond(),
    ))->toBeFalse();

    expect(
        $heartbeats->find('analyses')?->dispatchedAt->equalTo(
            $newerDispatch,
        ),
    )->toBeTrue();
});

test('database and cache dependency failures return a sanitized 503 response', function (
    string $configurationKey,
    string $failedCheck,
) {
    config()->set($configurationKey, 'missing-connection');

    $response = $this->getJson(route('api.v1.health'))
        ->assertServiceUnavailable()
        ->assertJsonPath('data.status', 'unavailable')
        ->assertJsonPath("data.checks.{$failedCheck}", 'unavailable')
        ->assertJsonMissingPath('message')
        ->assertJsonMissingPath('exception');

    expect($response->getContent())
        ->not->toContain('missing-connection')
        ->not->toContain('SQLSTATE')
        ->not->toContain('Redis');
})->with([
    'database' => [
        'operations.readiness.database_connection',
        'database',
    ],
    'cache' => [
        'operations.readiness.cache_store',
        'cache',
    ],
]);

test('the deploy command can require queue monitoring to be activated', function () {
    config()->set(
        'operations.readiness.queue_heartbeats.enabled',
        false,
    );

    $this->artisan('operations:readiness', [
        '--require-queue-heartbeats' => true,
        '--json' => true,
    ])
        ->expectsOutputToContain('"queues":"not_configured"')
        ->assertFailed();
});

test('the JSON deploy command emits exactly one parseable document', function () {
    config()->set(
        'operations.readiness.queue_heartbeats.enabled',
        false,
    );

    $exitCode = Artisan::call('operations:readiness', ['--json' => true]);
    $payload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(0)
        ->and($payload)->toMatchArray([
            'status' => 'ok',
            'checks' => [
                'database' => 'ok',
                'cache' => 'ok',
                'queues' => 'not_monitored',
            ],
            'queue_heartbeats' => [],
        ]);
});
