<?php

use App\Jobs\Operations\RecordQueueThroughputProbe;
use App\Operations\Capacity\QueueThroughputConfiguration;
use App\Operations\Capacity\QueueThroughputRunner;
use App\Operations\Capacity\QueueThroughputStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('operations.readiness.cache_store', 'array');
    config()->set(
        'operations.readiness.queue_heartbeats.queues',
        ['analyses', 'connectors', 'notifications', 'default'],
    );
    config()->set('queue.default', 'sync');
    config()->set('performance.queue_throughput.default_jobs', 5);
    config()->set('performance.queue_throughput.maximum_jobs', 100);
    config()->set(
        'performance.queue_throughput.default_timeout_seconds',
        2,
    );
    config()->set(
        'performance.queue_throughput.maximum_timeout_seconds',
        5,
    );
    config()->set('performance.queue_throughput.receipt_ttl_seconds', 60);
    config()->set(
        'performance.queue_throughput.poll_interval_milliseconds',
        10,
    );
    config()->set(
        'performance.queue_throughput.budgets.minimum_throughput_per_second',
        0.001,
    );
    config()->set(
        'performance.queue_throughput.budgets.maximum_p95_latency_milliseconds',
        60000,
    );
    config()->set(
        'performance.queue_throughput.budgets.maximum_p99_latency_milliseconds',
        60000,
    );
    Cache::store('array')->flush();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('throughput receipts are unique validated and removed after the run', function () {
    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-08-05T18:00:00Z'),
    );
    $runId = (string) Str::ulid();
    $store = app(QueueThroughputStore::class);
    $store->start($runId, 2);

    expect($store->record(
        runId: $runId,
        sequence: 1,
        dispatchedAt: CarbonImmutable::now('UTC')->subMilliseconds(25),
    ))->toBeTrue()
        ->and($store->record(
            runId: $runId,
            sequence: 1,
            dispatchedAt: CarbonImmutable::now('UTC')->subMilliseconds(25),
        ))->toBeFalse();

    (new RecordQueueThroughputProbe(
        runId: $runId,
        sequence: 2,
        dispatchedAt: CarbonImmutable::now('UTC')
            ->subMilliseconds(7)
            ->toISOString(),
        queueName: 'analyses',
    ))->handle($store);

    $receipts = $store->collect($runId, 2);

    expect($receipts)->toHaveCount(2)
        ->and($receipts[1]->latencyMilliseconds)->toBe(25)
        ->and($receipts[2]->latencyMilliseconds)->toBe(7);

    $store->cleanup($runId, 2);

    expect($store->collect($runId, 2))->toBe([])
        ->and($store->record(
            runId: $runId,
            sequence: 2,
            dispatchedAt: CarbonImmutable::now('UTC'),
        ))->toBeFalse();
})->group('capacity');

test('a corrupt throughput receipt fails closed without exposing its payload', function () {
    $runId = (string) Str::ulid();
    $store = app(QueueThroughputStore::class);
    $store->start($runId, 1);
    Cache::store('array')->put(
        'performance:queue-throughput:v1:'.$runId.':receipt:1',
        ['version' => 1, 'unexpected' => 'private diagnostic'],
        60,
    );

    expect(fn (): array => $store->collect($runId, 1))
        ->toThrow(RuntimeException::class);

    $store->cleanup($runId, 1);

    expect(Cache::store('array')->get(
        'performance:queue-throughput:v1:'.$runId.':receipt:1',
    ))->toBeNull();
})->group('capacity');

test('the synchronous rehearsal reports complete bounded percentile evidence', function () {
    $report = app(QueueThroughputRunner::class)->run(
        queue: 'analyses',
        jobs: 5,
        timeoutSeconds: 2,
        budget: app(QueueThroughputConfiguration::class)->budget(
            null,
            null,
            null,
        ),
    );
    $payload = $report->toArray();

    expect($report->passed())->toBeTrue()
        ->and($payload['status'])->toBe('passed')
        ->and($payload['expected_jobs'])->toBe(5)
        ->and($payload['completed_jobs'])->toBe(5)
        ->and($payload['missed_jobs'])->toBe(0)
        ->and($payload['throughput_per_second'])->toBeGreaterThan(0)
        ->and($payload['latency_milliseconds']['p50'])->toBeInt()
        ->and($payload['latency_milliseconds']['p95'])->toBeInt()
        ->and($payload['latency_milliseconds']['p99'])->toBeInt()
        ->and($payload['error_code'])->toBeNull();

    expect(Cache::store('array')->get(
        'performance:queue-throughput:v1:'.$report->runId.':run',
    ))->toBeNull();
})->group('capacity');

test('the command emits one strict machine-readable throughput report', function () {
    $exitCode = Artisan::call('operations:queue-throughput', [
        '--queue' => 'analyses',
        '--jobs' => '5',
        '--timeout' => '2',
        '--acknowledge-load' => true,
        '--allow-non-staging' => true,
        '--json' => true,
    ]);
    $payload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(0)
        ->and($payload['status'])->toBe('passed')
        ->and($payload['environment'])->toBe('testing')
        ->and($payload['queue'])->toBe('analyses')
        ->and($payload['completed_jobs'])->toBe(5)
        ->and($payload['budget'])->toBe([
            'minimum_throughput_per_second' => 0.001,
            'maximum_p95_latency_milliseconds' => 60000,
            'maximum_p99_latency_milliseconds' => 60000,
        ]);
})->group('capacity');

test('the command requires staging and explicit load acknowledgement', function () {
    $exitCode = Artisan::call('operations:queue-throughput', [
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe([
            'status' => 'failed',
            'error_code' => 'staging_environment_required',
        ]);

    $exitCode = Artisan::call('operations:queue-throughput', [
        '--allow-non-staging' => true,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe([
            'status' => 'failed',
            'error_code' => 'load_acknowledgement_required',
        ]);
})->group('capacity');

test('production queue workloads remain forbidden without an override', function () {
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'production');

    try {
        $exitCode = Artisan::call('operations:queue-throughput', [
            '--acknowledge-load' => true,
            '--allow-non-staging' => true,
            '--json' => true,
        ]);
        $payload = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } finally {
        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }

    expect($exitCode)->toBe(1)
        ->and($payload)->toBe([
            'status' => 'failed',
            'error_code' => 'production_forbidden',
        ]);
})->group('capacity');

test('staging evidence requires redis and cannot weaken versioned budgets', function () {
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'staging');

    try {
        $exitCode = Artisan::call('operations:queue-throughput', [
            '--acknowledge-load' => true,
            '--json' => true,
        ]);
        $infrastructurePayload = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } finally {
        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }

    expect($exitCode)->toBe(1)
        ->and($infrastructurePayload)->toBe([
            'status' => 'failed',
            'error_code' => 'invalid_configuration',
        ]);

    $exitCode = Artisan::call('operations:queue-throughput', [
        '--minimum-throughput' => '0.0001',
        '--acknowledge-load' => true,
        '--allow-non-staging' => true,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe([
            'status' => 'failed',
            'error_code' => 'invalid_configuration',
        ]);
})->group('capacity');

test('an unprocessed batch fails with bounded timeout evidence', function () {
    Queue::fake();
    config()->set(
        'performance.queue_throughput.default_timeout_seconds',
        1,
    );

    $exitCode = Artisan::call('operations:queue-throughput', [
        '--jobs' => '3',
        '--timeout' => '1',
        '--acknowledge-load' => true,
        '--allow-non-staging' => true,
        '--json' => true,
    ]);
    $payload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('failed')
        ->and($payload['completed_jobs'])->toBe(0)
        ->and($payload['missed_jobs'])->toBe(3)
        ->and($payload['latency_milliseconds']['p95'])->toBeNull();

    Queue::assertPushed(RecordQueueThroughputProbe::class, 3);
    Queue::assertPushedOn('analyses', RecordQueueThroughputProbe::class);
})->group('capacity');
