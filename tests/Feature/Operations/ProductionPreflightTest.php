<?php

use App\Operations\Data\ProductionPreflightCheck;
use App\Operations\ProductionPreflight;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function configureProductionPreflightBaseline(): void
{
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
        'app.cipher' => 'AES-256-CBC',
        'app.url' => 'https://procura.example',
        'app.frontend_url' => 'https://procura.example',
        'cors.allowed_origins' => ['https://procura.example'],
        'sanctum.stateful' => ['procura.example'],
        'trustedproxy.proxies' => ['10.0.0.0/24', '2001:db8::1'],
        'database.default' => 'production',
        'database.connections.production' => [
            'driver' => 'mysql',
            'database' => 'procura_production',
            'username' => 'procura_app',
            'password' => 'database-secret-marker',
            'strict' => true,
            'charset' => 'utf8mb4',
            'timezone' => '+00:00',
        ],
        'database.redis.default.url' => 'rediss://redis.internal:6379',
        'cache.default' => 'redis',
        'cache.stores.redis.driver' => 'redis',
        'operations.readiness.cache_store' => 'redis',
        'operations.dashboard_metrics.cache_store' => 'redis',
        'operations.readiness.queue_heartbeats.enabled' => true,
        'operations.readiness.queue_heartbeats.queues' => [
            'analyses',
            'connectors',
            'notifications',
            'default',
        ],
        'performance.analysis_pipeline_workload.enabled' => false,
        'performance.analysis_pipeline_metrics.enabled' => false,
        'performance.analysis_pipeline_metrics.retention_days' => 30,
        'performance.sell_price_intelligence_metrics.enabled' => true,
        'performance.sell_price_intelligence_metrics.retention_days' => 30,
        'queue.default' => 'redis',
        'queue.connections.redis' => [
            'driver' => 'redis',
            'retry_after' => 960,
        ],
        'queue.failed.driver' => 'database-uuids',
        'session.driver' => 'redis',
        'session.secure' => true,
        'session.http_only' => true,
        'session.encrypt' => true,
        'session.same_site' => 'lax',
        'session.lifetime' => 120,
        'filesystems.default' => 's3',
        'filesystems.disks.s3' => [
            'driver' => 's3',
            'bucket' => 'procura-private',
            'visibility' => 'private',
            'throw' => true,
            'key' => 'storage-key-secret-marker',
            'secret' => 'storage-secret-marker',
        ],
        'listings.uploads.disk' => 's3',
        'owned_products.uploads.disk' => 's3',
        'marketplace_connectors.import_disk' => 's3',
        'broker.report_disk' => 's3',
        'logging.default' => 'stderr',
        'logging.channels.stderr.driver' => 'monolog',
        'analyses.provider' => 'fake',
        'analyses.submission_enabled' => false,
        'product_matching.provider' => 'fake',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.transport' => 'smtp',
        'mail.from.address' => 'notifications@procura.example',
        'broker.requests_enabled' => true,
        'broker.offers_enabled' => false,
        'broker.transactions_enabled' => false,
        'broker.payment_cases_enabled' => false,
        'broker.reports_enabled' => false,
        'broker.commission_rule_version' => 'broker-commission:v1',
        'broker.commission_rate_basis_points' => 250,
        'billing.checkout_enabled' => false,
        'monitoring.telegram.bot_token' => '',
        'monitoring.telegram.bot_username' => '',
        'monitoring.telegram.webhook_secret' => '',
        'monitoring.telegram.identity_hash_key' => '',
        'marketplace_connectors.csv_import_enabled' => false,
        'analyses.manual_retry_enabled' => false,
        'privacy.fulfillment.enabled' => false,
        'privacy.erasure.enabled' => false,
    ]);
}

function productionPreflightStatus(string $key): string
{
    $check = collect(app(ProductionPreflight::class)->inspect()->checks)
        ->first(
            static fn (ProductionPreflightCheck $check): bool => (
                $check->key === $key
            ),
        );

    expect($check)->toBeInstanceOf(ProductionPreflightCheck::class);

    return $check->status;
}

beforeEach(function (): void {
    configureProductionPreflightBaseline();
});

afterEach(function (): void {
    DB::setDefaultConnection('sqlite');
    config()->set('database.default', 'sqlite');
});

test('a safe base configuration is deployable while external activation remains explicit', function () {
    $report = app(ProductionPreflight::class)->inspect();
    $payload = $report->operatorPayload();

    expect($report->deploymentReady())->toBeTrue()
        ->and($report->launchReady())->toBeFalse()
        ->and($report->status())->toBe('review_required')
        ->and($payload['summary']['failed'])->toBe(0)
        ->and($payload['summary']['warnings'])->toBeGreaterThan(0)
        ->and($payload['checks']['data.private_storage']['status'])->toBe('pass')
        ->and($payload['checks']['operations.performance_workloads']['status'])->toBe('pass')
        ->and($payload['checks']['operations.analysis_pipeline_metrics']['status'])->toBe('pass')
        ->and($payload['checks']['operations.sell_price_intelligence_metrics']['status'])->toBe('pass')
        ->and($payload['checks']['operations.queue_heartbeats']['status'])->toBe('pass');
});

test('production preflight fails when analysis workload permits are enabled', function () {
    config()->set('performance.analysis_pipeline_workload.enabled', true);

    expect(productionPreflightStatus('operations.performance_workloads'))
        ->toBe('fail');
});

test('enabled analysis submission requires valid pipeline metrics', function () {
    config([
        'analyses.submission_enabled' => true,
        'performance.analysis_pipeline_metrics.enabled' => false,
    ]);

    expect(productionPreflightStatus('operations.analysis_pipeline_metrics'))
        ->toBe('fail');

    config([
        'performance.analysis_pipeline_metrics.enabled' => true,
        'performance.analysis_pipeline_metrics.retention_days' => 91,
    ]);

    expect(productionPreflightStatus('operations.analysis_pipeline_metrics'))
        ->toBe('fail');
});

test('Sell price intelligence requires enabled valid production metrics', function () {
    config()->set('performance.sell_price_intelligence_metrics.enabled', false);

    expect(productionPreflightStatus('operations.sell_price_intelligence_metrics'))
        ->toBe('fail');

    config([
        'performance.sell_price_intelligence_metrics.enabled' => true,
        'performance.sell_price_intelligence_metrics.retention_days' => 91,
    ]);

    expect(productionPreflightStatus('operations.sell_price_intelligence_metrics'))
        ->toBe('fail');
});

test('the command emits one secret-free JSON document and strict mode blocks warnings', function () {
    $exitCode = Artisan::call('operations:production-preflight', [
        '--json' => true,
    ]);
    $output = trim(Artisan::output());
    $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['status'])->toBe('review_required')
        ->and($output)->not->toContain('storage-key-secret-marker')
        ->and($output)->not->toContain('storage-secret-marker')
        ->and($output)->not->toContain('database-secret-marker')
        ->and(substr_count($output, PHP_EOL))->toBe(0);

    $this->artisan('operations:production-preflight', [
        '--strict' => true,
        '--json' => true,
    ])->assertFailed();
});

test('production environment is required unless rehearsal is explicit', function () {
    config()->set('app.env', 'staging');

    expect(productionPreflightStatus('runtime.environment'))->toBe('fail');

    $report = app(ProductionPreflight::class)->inspect(
        allowNonProduction: true,
    );
    $environment = collect($report->checks)->first(
        static fn (ProductionPreflightCheck $check): bool => (
            $check->key === 'runtime.environment'
        ),
    );

    expect($environment?->status)->toBe('warning');
});

test('debug mode and an invalid application key block deployment', function () {
    config([
        'app.debug' => true,
        'app.key' => 'not-a-production-key',
    ]);

    expect(productionPreflightStatus('runtime.debug'))->toBe('fail')
        ->and(productionPreflightStatus('runtime.encryption_key'))->toBe('fail');
});

test('https origin and explicit trusted proxy boundaries fail closed', function () {
    config([
        'app.frontend_url' => 'http://procura.example',
        'trustedproxy.proxies' => ['0.0.0.0/0'],
    ]);

    expect(productionPreflightStatus('http.origins'))->toBe('fail')
        ->and(productionPreflightStatus('http.trusted_boundary'))->toBe('fail');
});

test('local data-plane drivers and unsafe queue visibility block deployment', function () {
    config([
        'database.connections.production.username' => 'root',
        'cache.default' => 'database',
        'queue.connections.redis.retry_after' => 900,
    ]);

    expect(productionPreflightStatus('data.database'))->toBe('fail')
        ->and(productionPreflightStatus('data.shared_cache'))->toBe('fail')
        ->and(productionPreflightStatus('data.queue'))->toBe('fail');
});

test('a non UTC or non utf8mb4 production database blocks deployment', function () {
    config([
        'database.connections.production.charset' => 'latin1',
        'database.connections.production.timezone' => 'SYSTEM',
    ]);

    expect(productionPreflightStatus('data.database'))->toBe('fail');
});

test('insecure sessions and local private evidence storage block deployment', function () {
    config([
        'session.secure' => false,
        'listings.uploads.disk' => 'local',
    ]);

    expect(productionPreflightStatus('security.session'))->toBe('fail')
        ->and(productionPreflightStatus('data.private_storage'))->toBe('fail');
});

test('fake providers and log mail cannot pass production preflight', function () {
    config([
        'analyses.provider' => 'fake',
        'analyses.submission_enabled' => true,
        'mail.default' => 'log',
        'mail.mailers.log.transport' => 'log',
    ]);

    expect(productionPreflightStatus('providers.analysis'))->toBe('fail')
        ->and(productionPreflightStatus('providers.mail'))->toBe('fail');
});

test('inconsistent broker switches and enabled incomplete billing fail closed', function () {
    config([
        'broker.requests_enabled' => true,
        'broker.offers_enabled' => false,
        'broker.transactions_enabled' => true,
        'billing.checkout_enabled' => true,
        'cashier.key' => 'pk_live_secret-marker',
        'cashier.secret' => null,
        'cashier.webhook.secret' => null,
    ]);

    expect(productionPreflightStatus('features.dependencies'))->toBe('fail')
        ->and(productionPreflightStatus('optional.billing'))->toBe('fail');
});

test('invalid broker monitoring thresholds block production preflight', function () {
    config(['broker.monitoring.request_age_hours' => 0]);

    expect(productionPreflightStatus('features.dependencies'))->toBe('fail');
});

test('operator output contains stable check keys but no configured credentials', function () {
    config([
        'monitoring.telegram.bot_token' => 'telegram-token-secret-marker',
        'monitoring.telegram.bot_username' => 'procura_bot',
        'monitoring.telegram.webhook_secret' => 'telegram-webhook-secret-marker',
        'monitoring.telegram.identity_hash_key' => 'telegram-hash-secret-marker',
        'monitoring.telegram.webhook_url' => 'https://procura.example/api/v1/integrations/telegram/webhook',
    ]);

    $payload = json_encode(
        app(ProductionPreflight::class)->inspect()->operatorPayload(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );

    expect($payload)->toContain('optional.telegram')
        ->not->toContain('telegram-token-secret-marker')
        ->not->toContain('telegram-webhook-secret-marker')
        ->not->toContain('telegram-hash-secret-marker');
});

test('the sanitized production template has unique keys and no committed credentials', function () {
    $lines = file(base_path(
        'deploy/env/procura.production.env.example',
    ), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($lines)->toBeArray();

    $values = [];

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');

        expect($key)->toMatch('/^[A-Z][A-Z0-9_]+$/')
            ->and(array_key_exists($key, $values))->toBeFalse();
        $values[$key] = $value;
    }

    foreach ([
        'APP_KEY',
        'DB_PASSWORD',
        'REDIS_URL',
        'MAIL_PASSWORD',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'STRIPE_SECRET',
        'STRIPE_WEBHOOK_SECRET',
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_WEBHOOK_SECRET',
        'TELEGRAM_IDENTITY_HASH_KEY',
    ] as $secret) {
        expect($values[$secret] ?? null)->toBe('');
    }

    expect($values)->toMatchArray([
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'ANALYSIS_SUBMISSION_ENABLED' => 'false',
        'CACHE_STORE' => 'redis',
        'QUEUE_CONNECTION' => 'redis',
        'DB_TIMEZONE' => '+00:00',
        'SESSION_ENCRYPT' => 'true',
        'SESSION_SECURE_COOKIE' => 'true',
        'AWS_THROW' => 'true',
    ]);
});
