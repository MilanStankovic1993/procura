<?php

use App\Operations\Capacity\SaturationSoakEvidenceVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

const SATURATION_EVIDENCE_RELEASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const SATURATION_EVIDENCE_FILE = 'saturation-test.json';

beforeEach(function (): void {
    config()->set(
        'operations.readiness.queue_heartbeats.queues',
        ['analyses', 'connectors', 'notifications', 'default'],
    );
    config()->set(
        'performance.saturation_soak_evidence.phases.baseline.minimum_duration_seconds',
        15,
    );
    config()->set(
        'performance.saturation_soak_evidence.phases.saturation.minimum_duration_seconds',
        15,
    );
    config()->set(
        'performance.saturation_soak_evidence.phases.soak.minimum_duration_seconds',
        15,
    );
    config()->set(
        'performance.saturation_soak_evidence.phases.recovery.minimum_duration_seconds',
        15,
    );
});

afterEach(function (): void {
    File::delete(saturationEvidencePath());
});

/**
 * @return array<string, mixed>
 */
function saturationEvidencePayload(): array
{
    $startedAt = CarbonImmutable::parse('2026-08-11T10:00:00Z');
    $phases = [
        'baseline',
        'baseline',
        'saturation',
        'saturation',
        'soak',
        'soak',
        'recovery',
        'recovery',
    ];
    $samples = [];

    foreach ($phases as $index => $phase) {
        $active = in_array($phase, ['saturation', 'soak'], true);
        $last = $index === array_key_last($phases);
        $recovery = $phase === 'recovery';
        $queueDepth = $active ? 10 : ($recovery && ! $last ? 1 : 0);
        $oldestAge = $active ? 1000 : ($recovery && ! $last ? 500 : 0);
        $resourceUtilization = $active ? 6000 : ($last ? 2500 : 3000);
        $workerUtilization = $active ? 6000 : ($last ? 1000 : 2000);
        $queues = [];
        $workers = [];

        foreach (['analyses', 'connectors', 'notifications', 'default'] as $queue) {
            $queues[$queue] = [
                'depth' => $queueDepth,
                'oldest_job_age_milliseconds' => $oldestAge,
            ];
            $workers[$queue] = [
                'busy_utilization_basis_points' => $workerUtilization,
                'restarts_total' => 2,
            ];
        }

        $samples[] = [
            'observed_at' => $startedAt
                ->addSeconds($index * 15)
                ->format('Y-m-d\TH:i:s\Z'),
            'phase' => $phase,
            'database' => [
                'connection_utilization_basis_points' => (
                    $resourceUtilization
                ),
                'cpu_utilization_basis_points' => (
                    $active ? 5500 : $resourceUtilization
                ),
                'deadlocks_total' => 10,
            ],
            'redis' => [
                'memory_utilization_basis_points' => (
                    $resourceUtilization
                ),
                'cpu_utilization_basis_points' => (
                    $active ? 5500 : $resourceUtilization
                ),
                'evicted_keys_total' => 20,
                'rejected_connections_total' => 3,
            ],
            'host' => [
                'cpu_utilization_basis_points' => $resourceUtilization,
                'memory_utilization_basis_points' => $resourceUtilization,
            ],
            'application' => [
                'http_requests_total' => 1000 + ($index * 400),
                'http_server_errors_total' => 0,
                'jobs_processed_total' => 500 + ($index * 40),
                'jobs_failed_total' => 0,
            ],
            'queues' => $queues,
            'workers' => $workers,
        ];
    }

    return [
        'contract_version' => 'capacity-saturation-soak-input:v1',
        'release_sha' => SATURATION_EVIDENCE_RELEASE,
        'environment' => 'staging',
        'sample_interval_seconds' => 15,
        'samples' => $samples,
    ];
}

function saturationEvidencePath(): string
{
    return storage_path(
        'app/private/performance-evidence/'.SATURATION_EVIDENCE_FILE,
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function writeSaturationEvidence(array $payload): void
{
    File::ensureDirectoryExists(dirname(saturationEvidencePath()));
    File::put(
        saturationEvidencePath(),
        json_encode($payload, JSON_THROW_ON_ERROR),
    );
}

test('valid saturation and soak telemetry produces aggregate evidence', function () {
    $report = app(SaturationSoakEvidenceVerifier::class)->verify(
        saturationEvidencePayload(),
        SATURATION_EVIDENCE_RELEASE,
    );
    $payload = $report->toArray();

    expect($report->passed())->toBeTrue()
        ->and($report->releaseEvidence())->toBeFalse()
        ->and($payload['status'])->toBe('passed')
        ->and($payload['release_evidence'])->toBeFalse()
        ->and($payload['report_contract_version'])
        ->toBe('capacity-saturation-soak-report:v1')
        ->and($payload['budget_version'])
        ->toBe('capacity-saturation-soak-budget:v1')
        ->and($payload['sample_count'])->toBe(8)
        ->and($payload['phase_durations_seconds'])->toBe([
            'baseline' => 15,
            'saturation' => 15,
            'soak' => 15,
            'recovery' => 15,
        ])
        ->and($payload['metrics']['counter_deltas']['http_requests'])
        ->toBe(2800)
        ->and($payload['metrics']['counter_deltas']['jobs_processed'])
        ->toBe(280)
        ->and($payload['metrics']['load_counter_deltas']['http_requests'])
        ->toBe(1200)
        ->and($payload['metrics']['load_counter_deltas']['jobs_processed'])
        ->toBe(120)
        ->and($payload['metrics']['soak_pressure_basis_points'][
            'database'
        ]['p50'])->toBe(6000)
        ->and($payload['metrics']['queues']['analyses']['depth']['maximum'])
        ->toBe(10)
        ->and($payload['failed_checks'])->toBe([]);

    expect(json_encode($payload, JSON_THROW_ON_ERROR))
        ->not->toContain('observed_at')
        ->not->toContain('hostname')
        ->not->toContain('email')
        ->not->toContain('password');
})->group('capacity');

test('the published collector schema matches the application contract', function () {
    $schema = json_decode(
        File::get(base_path(
            'tools/performance/saturation-soak-evidence.schema.json',
        )),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $queueKeys = array_keys(
        $schema['$defs']['sample']['properties']['queues']['properties'],
    );

    expect($schema['properties']['contract_version']['const'])
        ->toBe(config(
            'performance.saturation_soak_evidence.input_contract_version',
        ))
        ->and($schema['properties']['sample_interval_seconds']['minimum'])
        ->toBe(config(
            'performance.saturation_soak_evidence.sample_interval_seconds.minimum',
        ))
        ->and($schema['properties']['sample_interval_seconds']['maximum'])
        ->toBe(config(
            'performance.saturation_soak_evidence.sample_interval_seconds.maximum',
        ))
        ->and($schema['properties']['samples']['maxItems'])
        ->toBe(config(
            'performance.saturation_soak_evidence.maximum_samples',
        ))
        ->and($queueKeys)->toBe([
            'analyses',
            'connectors',
            'notifications',
            'default',
        ]);
})->group('capacity');

test('the command seals staging evidence to the exact release', function () {
    writeSaturationEvidence(saturationEvidencePayload());
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'staging');

    try {
        $exitCode = Artisan::call(
            'operations:verify-saturation-soak-evidence',
            [
                '--input' => SATURATION_EVIDENCE_FILE,
                '--expected-release' => SATURATION_EVIDENCE_RELEASE,
                '--json' => true,
            ],
        );
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

    expect($exitCode)->toBe(0)
        ->and($payload['status'])->toBe('passed')
        ->and($payload['release_evidence'])->toBeTrue()
        ->and($payload['environment'])->toBe('staging')
        ->and($payload['evidence_environment'])->toBe('staging')
        ->and($payload['release_sha'])->toBe(SATURATION_EVIDENCE_RELEASE);
})->group('capacity');

test('a local rehearsal requires an override and is never release evidence', function () {
    writeSaturationEvidence(saturationEvidencePayload());

    $exitCode = Artisan::call(
        'operations:verify-saturation-soak-evidence',
        [
            '--input' => SATURATION_EVIDENCE_FILE,
            '--expected-release' => SATURATION_EVIDENCE_RELEASE,
            '--json' => true,
        ],
    );

    expect($exitCode)->toBe(1)
        ->and(json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe([
            'status' => 'failed',
            'error_code' => 'staging_environment_required',
        ]);

    $exitCode = Artisan::call(
        'operations:verify-saturation-soak-evidence',
        [
            '--input' => SATURATION_EVIDENCE_FILE,
            '--expected-release' => SATURATION_EVIDENCE_RELEASE,
            '--allow-local-rehearsal' => true,
            '--json' => true,
        ],
    );
    $payload = json_decode(
        trim(Artisan::output()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($exitCode)->toBe(0)
        ->and($payload['status'])->toBe('passed')
        ->and($payload['release_evidence'])->toBeFalse()
        ->and($payload['environment'])->toBe('testing');
})->group('capacity');

test('production verification is permanently forbidden', function () {
    writeSaturationEvidence(saturationEvidencePayload());
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'production');

    try {
        $exitCode = Artisan::call(
            'operations:verify-saturation-soak-evidence',
            [
                '--input' => SATURATION_EVIDENCE_FILE,
                '--expected-release' => SATURATION_EVIDENCE_RELEASE,
                '--allow-local-rehearsal' => true,
                '--json' => true,
            ],
        );
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

test('private input traversal and missing files fail before parsing', function () {
    $exitCode = Artisan::call(
        'operations:verify-saturation-soak-evidence',
        [
            '--input' => '../outside.json',
            '--expected-release' => SATURATION_EVIDENCE_RELEASE,
            '--allow-local-rehearsal' => true,
            '--json' => true,
        ],
    );

    expect($exitCode)->toBe(1)
        ->and(json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe([
            'status' => 'failed',
            'error_code' => 'invalid_input',
        ]);
})->group('capacity');

test('unknown fields and release mismatches fail the sealed contract', function () {
    $payload = saturationEvidencePayload();
    $payload['samples'][0]['hostname'] = 'must-not-be-accepted';
    writeSaturationEvidence($payload);

    $exitCode = Artisan::call(
        'operations:verify-saturation-soak-evidence',
        [
            '--input' => SATURATION_EVIDENCE_FILE,
            '--expected-release' => SATURATION_EVIDENCE_RELEASE,
            '--allow-local-rehearsal' => true,
            '--json' => true,
        ],
    );

    expect($exitCode)->toBe(1)
        ->and(json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe([
            'status' => 'failed',
            'error_code' => 'invalid_evidence',
        ]);

    writeSaturationEvidence(saturationEvidencePayload());
    $exitCode = Artisan::call(
        'operations:verify-saturation-soak-evidence',
        [
            '--input' => SATURATION_EVIDENCE_FILE,
            '--expected-release' => str_repeat('b', 40),
            '--allow-local-rehearsal' => true,
            '--json' => true,
        ],
    );

    expect($exitCode)->toBe(1)
        ->and(json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe([
            'status' => 'failed',
            'error_code' => 'invalid_evidence',
        ]);
})->group('capacity');

test('timeline phase and counter corruption fail closed', function () {
    $payload = saturationEvidencePayload();
    $payload['samples'][3]['phase'] = 'baseline';

    expect(fn () => app(SaturationSoakEvidenceVerifier::class)->verify(
        $payload,
        SATURATION_EVIDENCE_RELEASE,
    ))->toThrow(RuntimeException::class);

    $payload = saturationEvidencePayload();
    $payload['samples'][4]['application']['http_requests_total'] = 1;

    expect(fn () => app(SaturationSoakEvidenceVerifier::class)->verify(
        $payload,
        SATURATION_EVIDENCE_RELEASE,
    ))->toThrow(RuntimeException::class);

    $payload = saturationEvidencePayload();
    $payload['samples'][4]['observed_at'] = '2026-08-11T10:30:00+00:00';

    expect(fn () => app(SaturationSoakEvidenceVerifier::class)->verify(
        $payload,
        SATURATION_EVIDENCE_RELEASE,
    ))->toThrow(RuntimeException::class);
})->group('capacity');

test('resource queue error and recovery regressions are reported together', function () {
    $payload = saturationEvidencePayload();

    foreach ($payload['samples'] as &$sample) {
        if (in_array($sample['phase'], ['saturation', 'soak'], true)) {
            $sample['database']['connection_utilization_basis_points'] = 9000;
            $sample['workers']['analyses'][
                'busy_utilization_basis_points'
            ] = 4000;
            $sample['queues']['analyses']['depth'] = 600;
        }
    }

    unset($sample);
    $last = array_key_last($payload['samples']);
    $payload['samples'][$last]['application']['http_server_errors_total'] = 1;
    $payload['samples'][$last]['database']['deadlocks_total'] = 11;
    $payload['samples'][$last]['queues']['analyses']['depth'] = 1;
    $payload['samples'][$last]['host']['cpu_utilization_basis_points'] = 8000;

    $report = app(SaturationSoakEvidenceVerifier::class)->verify(
        $payload,
        SATURATION_EVIDENCE_RELEASE,
    );

    expect($report->passed())->toBeFalse()
        ->and($report->failedChecks)->toContain(
            'http_server_error_rate',
            'database_deadlocks',
            'database_connections_p95',
            'queue_analyses_depth',
            'worker_analyses_soak_pressure',
            'recovery_queue_analyses_depth',
            'recovery_host_cpu',
        );
})->group('capacity');

test('a short saturation peak cannot hide an underloaded soak window', function () {
    $payload = saturationEvidencePayload();

    foreach ($payload['samples'] as &$sample) {
        if ($sample['phase'] !== 'soak') {
            continue;
        }

        $sample['database']['connection_utilization_basis_points'] = 3000;
        $sample['database']['cpu_utilization_basis_points'] = 3000;
        $sample['redis']['memory_utilization_basis_points'] = 3000;
        $sample['redis']['cpu_utilization_basis_points'] = 3000;

        foreach ($sample['workers'] as &$worker) {
            $worker['busy_utilization_basis_points'] = 3000;
        }

        unset($worker);
    }

    unset($sample);
    $report = app(SaturationSoakEvidenceVerifier::class)->verify(
        $payload,
        SATURATION_EVIDENCE_RELEASE,
    );

    expect($report->passed())->toBeFalse()
        ->and($report->failedChecks)->toContain(
            'database_soak_pressure',
            'redis_soak_pressure',
            'worker_analyses_soak_pressure',
            'worker_connectors_soak_pressure',
            'worker_notifications_soak_pressure',
            'worker_default_soak_pressure',
        );
})->group('capacity');

test('invalid queue budgets fail configuration before reading evidence', function () {
    config()->set(
        'performance.saturation_soak_evidence.budgets.maximum_queue_depth',
        ['analyses' => 100],
    );

    $exitCode = Artisan::call(
        'operations:verify-saturation-soak-evidence',
        [
            '--input' => SATURATION_EVIDENCE_FILE,
            '--expected-release' => SATURATION_EVIDENCE_RELEASE,
            '--allow-local-rehearsal' => true,
            '--json' => true,
        ],
    );

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
