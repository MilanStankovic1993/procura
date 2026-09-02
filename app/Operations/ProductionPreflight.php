<?php

namespace App\Operations;

use App\Analysis\Contracts\ConfiguredListingAiAnalyzer;
use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Metrics\AnalysisPipelineMetricsConfiguration;
use App\Analysis\Providers\FakeListingAiAnalyzer;
use App\Billing\BillingConfiguration;
use App\BrokerRequests\Operations\BrokerOperationsConfiguration;
use App\Enums\Subscriptions\BillingInterval;
use App\Enums\Subscriptions\PlanCode;
use App\Monitoring\Telegram\TelegramConfiguration;
use App\Operations\Data\ProductionPreflightCheck;
use App\Operations\Data\ProductionPreflightReport;
use App\Privacy\PrivacyWorkflowConfiguration;
use App\ProductMatching\Contracts\ProductMatcher;
use App\ProductMatching\Providers\FakeCatalogProductMatcher;
use App\SellPriceIntelligence\Metrics\SellPriceIntelligenceMetricsConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Throwable;

final class ProductionPreflight
{
    /** @var list<ProductionPreflightCheck> */
    private array $checks = [];

    public function __construct(
        private readonly BillingConfiguration $billing,
        private readonly TelegramConfiguration $telegram,
        private readonly PrivacyWorkflowConfiguration $privacy,
        private readonly BrokerOperationsConfiguration $brokerOperations,
        private readonly AnalysisPipelineMetricsConfiguration $pipelineMetrics,
        private readonly SellPriceIntelligenceMetricsConfiguration $sellMetrics,
    ) {}

    public function inspect(bool $allowNonProduction = false): ProductionPreflightReport
    {
        $this->checks = [];

        $this->inspectRuntime($allowNonProduction);
        $this->inspectHttpBoundary();
        $this->inspectDataPlane();
        $this->inspectSession();
        $this->inspectPrivateStorage();
        $this->inspectOperations();
        $this->inspectApplicationProviders();
        $this->inspectFeatureDependencies();
        $this->inspectOptionalIntegrations();
        $this->inspectReleaseArtifact();

        return new ProductionPreflightReport(
            environment: (string) config('app.env'),
            checkedAt: CarbonImmutable::now('UTC'),
            checks: $this->checks,
        );
    }

    private function inspectRuntime(bool $allowNonProduction): void
    {
        $production = config('app.env') === 'production';

        if ($production) {
            $this->pass('runtime.environment', 'The effective environment is production.');
        } elseif ($allowNonProduction) {
            $this->warning(
                'runtime.environment',
                'A non-production environment was explicitly allowed for configuration rehearsal.',
            );
        } else {
            $this->fail(
                'runtime.environment',
                'APP_ENV must be production for a production preflight.',
            );
        }

        $this->result(
            'runtime.debug',
            config('app.debug') === false,
            'Debug rendering is disabled.',
            'APP_DEBUG must be false.',
        );

        $this->result(
            'runtime.encryption_key',
            $this->validEncryptionKey(),
            'The configured application key matches the selected cipher.',
            'APP_KEY is missing or invalid for the selected cipher.',
        );

        if (app()->configurationIsCached() && app()->routesAreCached()) {
            $this->pass(
                'runtime.optimization',
                'Configuration and routes are cached for the immutable release.',
            );
        } else {
            $this->warning(
                'runtime.optimization',
                'Run php artisan optimize in the inactive release before activation.',
            );
        }
    }

    private function inspectHttpBoundary(): void
    {
        $appUrl = trim((string) config('app.url'));
        $frontendUrl = trim((string) config('app.frontend_url'));
        $appOrigin = $this->httpsOrigin($appUrl);
        $frontendOrigin = $this->httpsOrigin($frontendUrl);
        $allowedOrigins = config('cors.allowed_origins', []);
        $statefulDomains = config('sanctum.stateful', []);
        $host = parse_url($appUrl, PHP_URL_HOST);
        $hostWithPort = $this->hostWithPort($appUrl);

        $originsValid = $appOrigin !== null
            && $frontendOrigin !== null
            && hash_equals($appOrigin, $frontendOrigin)
            && is_array($allowedOrigins)
            && $allowedOrigins !== []
            && count($allowedOrigins) <= 10;

        if ($originsValid) {
            foreach ($allowedOrigins as $origin) {
                if (
                    ! is_string($origin)
                    || $this->httpsOrigin($origin) !== $appOrigin
                ) {
                    $originsValid = false;
                    break;
                }
            }
        }

        $statefulValid = is_string($host)
            && $host !== ''
            && is_array($statefulDomains)
            && in_array($hostWithPort ?? $host, $statefulDomains, true);

        $this->result(
            'http.origins',
            $originsValid && $statefulValid,
            'Application, frontend, CORS, and stateful SPA origins share one HTTPS boundary.',
            'APP_URL, FRONTEND_URL, FRONTEND_URLS, and SANCTUM_STATEFUL_DOMAINS must define the same HTTPS origin.',
        );

        $trustedProxies = config('trustedproxy.proxies', []);
        $proxiesValid = is_array($trustedProxies)
            && $trustedProxies !== []
            && count($trustedProxies) <= 32;

        if ($proxiesValid) {
            foreach ($trustedProxies as $proxy) {
                if (! is_string($proxy) || ! $this->validProxy($proxy)) {
                    $proxiesValid = false;
                    break;
                }
            }
        }

        $this->result(
            'http.trusted_boundary',
            $proxiesValid,
            'Host validation is enabled and trusted proxies are explicitly bounded.',
            'TRUSTED_PROXIES must contain only explicit proxy IP addresses or CIDRs; catch-all trust is prohibited.',
        );
    }

    private function inspectDataPlane(): void
    {
        $databaseName = (string) config('database.default');
        $database = config("database.connections.{$databaseName}", []);
        $databaseValid = is_array($database)
            && ($database['driver'] ?? null) === 'mysql'
            && ($database['strict'] ?? null) === true
            && ($database['charset'] ?? null) === 'utf8mb4'
            && ($database['timezone'] ?? null) === '+00:00'
            && $this->configuredString($database['database'] ?? null) !== null
            && (
                $this->configuredString($database['password'] ?? null) !== null
                || $this->configuredString($database['url'] ?? null) !== null
            )
            && ! in_array(
                strtolower((string) ($database['database'] ?? '')),
                ['laravel', 'test', 'testing'],
                true,
            )
            && ! in_array(
                strtolower((string) ($database['username'] ?? '')),
                ['', 'root'],
                true,
            );

        $this->result(
            'data.database',
            $databaseValid,
            'The primary database uses strict UTC/utf8mb4 MySQL with a dedicated application identity.',
            'Production requires strict UTC/utf8mb4 MySQL, a dedicated database/user, and secret-manager supplied authentication.',
        );

        $redisUrl = trim((string) config('database.redis.default.url'));
        $redisSecure = str_starts_with(strtolower($redisUrl), 'rediss://');

        $this->result(
            'data.redis_transport',
            $redisSecure,
            'Redis uses an authenticated TLS URL supplied through effective configuration.',
            'REDIS_URL must use rediss:// for encrypted production cache and queue transport.',
        );

        $cacheStores = [
            (string) config('cache.default'),
            (string) (config('operations.readiness.cache_store') ?: config('cache.default')),
            (string) (config('operations.dashboard_metrics.cache_store') ?: config('cache.default')),
        ];
        $cacheValid = true;

        foreach ($cacheStores as $store) {
            if (config("cache.stores.{$store}.driver") !== 'redis') {
                $cacheValid = false;
                break;
            }
        }

        $this->result(
            'data.shared_cache',
            $cacheValid,
            'Application, readiness, locks, and dashboard metrics use shared Redis cache stores.',
            'CACHE_STORE and both operations cache stores must resolve to Redis.',
        );

        $queueName = (string) config('queue.default');
        $queue = config("queue.connections.{$queueName}", []);
        $maximumJobTimeout = max(
            60,
            (int) config('marketplace_connectors.processing_timeout_seconds', 900),
            (int) config('monitoring.email_delivery.timeout_seconds', 30),
            (int) config('monitoring.telegram.delivery.timeout_seconds', 20),
        );
        $queueValid = is_array($queue)
            && ($queue['driver'] ?? null) === 'redis'
            && is_int($queue['retry_after'] ?? null)
            && $queue['retry_after'] > $maximumJobTimeout
            && config('queue.failed.driver') === 'database-uuids';

        $this->result(
            'data.queue',
            $queueValid,
            'The queue uses Redis, durable failure records, and retry_after exceeds every job timeout.',
            'Production requires Redis queues, database UUID failures, and retry_after greater than the 900-second connector timeout.',
        );
    }

    private function inspectSession(): void
    {
        $driver = (string) config('session.driver');
        $valid = in_array($driver, ['database', 'redis'], true)
            && config('session.secure') === true
            && config('session.http_only') === true
            && config('session.encrypt') === true
            && in_array(config('session.same_site'), ['lax', 'strict'], true)
            && (int) config('session.lifetime') >= 5
            && (int) config('session.lifetime') <= 1440;

        $this->result(
            'security.session',
            $valid,
            'Sessions are shared, encrypted, HTTPS-only, HTTP-only, and same-site protected.',
            'Use database/Redis sessions with encryption, secure and HTTP-only cookies, and lax/strict SameSite.',
        );
    }

    private function inspectPrivateStorage(): void
    {
        $disks = array_unique([
            (string) config('filesystems.default'),
            (string) config('listings.uploads.disk'),
            (string) config('owned_products.uploads.disk'),
            (string) config('marketplace_connectors.import_disk'),
            (string) config('broker.report_disk'),
        ]);
        $valid = $disks !== [''];

        foreach ($disks as $disk) {
            $configuration = config("filesystems.disks.{$disk}");

            if (
                ! is_array($configuration)
                || ($configuration['driver'] ?? null) !== 's3'
                || ($configuration['visibility'] ?? null) !== 'private'
                || ($configuration['throw'] ?? null) !== true
                || $this->configuredString($configuration['bucket'] ?? null) === null
            ) {
                $valid = false;
                break;
            }
        }

        $this->result(
            'data.private_storage',
            $valid,
            'Every durable private artifact disk uses private fail-loud S3-compatible storage.',
            'Default, listing, owned-product, import, and report disks must resolve to private S3 storage with AWS_THROW=true and a bucket.',
        );
    }

    private function inspectOperations(): void
    {
        $this->result(
            'operations.performance_workloads',
            config('performance.analysis_pipeline_workload.enabled') === false
                && config('performance.browser_workload.enabled') === false,
            'Analysis and browser workload permits are disabled in production.',
            'PERFORMANCE_ANALYSIS_WORKLOAD_ENABLED and PERFORMANCE_BROWSER_WORKLOAD_ENABLED must remain false in production.',
        );

        $metricsValid = false;

        try {
            $this->pipelineMetrics->assertValid();
            $metricsValid = ! (bool) config('analyses.submission_enabled')
                || $this->pipelineMetrics->enabled();
        } catch (Throwable) {
            $metricsValid = false;
        }

        $this->result(
            'operations.analysis_pipeline_metrics',
            $metricsValid,
            'Analysis pipeline metric retention and versioned budgets are valid for the current activation state.',
            'Pipeline metric configuration must be valid, and enabled Analysis submission requires PERFORMANCE_ANALYSIS_METRICS_ENABLED=true.',
        );

        $sellMetricsValid = false;

        try {
            $this->sellMetrics->assertValid();
            $sellMetricsValid = $this->sellMetrics->enabled();
        } catch (Throwable) {
            $sellMetricsValid = false;
        }

        $this->result(
            'operations.sell_price_intelligence_metrics',
            $sellMetricsValid,
            'Sell price-intelligence metric retention and versioned budgets are valid and enabled.',
            'PERFORMANCE_SELL_METRICS_ENABLED must be true and the Sell metric retention/versioned budgets must be valid.',
        );

        $queues = config('operations.readiness.queue_heartbeats.queues', []);
        $required = ['analyses', 'connectors', 'notifications', 'default'];
        $enabled = config('operations.readiness.queue_heartbeats.enabled') === true;
        $configuredQueues = is_array($queues)
            ? array_values(array_unique(array_filter(
                $queues,
                static fn (mixed $queue): bool => is_string($queue),
            )))
            : [];
        sort($configuredQueues);
        sort($required);
        $validQueues = $configuredQueues === $required;

        if ($enabled && $validQueues) {
            $this->pass(
                'operations.queue_heartbeats',
                'All required worker-pool heartbeat checks are enabled.',
            );
        } else {
            $this->warning(
                'operations.queue_heartbeats',
                'Enable heartbeat monitoring for analyses, connectors, notifications, and default before launch.',
            );
        }

        $logChannel = (string) config('logging.default');
        $logDriver = config("logging.channels.{$logChannel}.driver");

        if (in_array($logDriver, ['errorlog', 'syslog', 'monolog'], true)) {
            $this->pass(
                'operations.logging',
                'The effective log channel is suitable for external aggregation.',
            );
        } else {
            $this->warning(
                'operations.logging',
                'Verify the configured log stack forwards to centralized immutable aggregation.',
            );
        }
    }

    private function inspectApplicationProviders(): void
    {
        if (! (bool) config('analyses.submission_enabled')) {
            $this->warning(
                'providers.analysis',
                'Analysis submission is disabled until approved non-fake analysis and matching providers are installed.',
            );
        } else {
            $providersValid = false;

            try {
                $analyzer = app(ListingAiAnalyzer::class);
                $matcher = app(ProductMatcher::class);
                $providersValid = $analyzer instanceof ConfiguredListingAiAnalyzer
                    && $analyzer->isConfigured()
                    && ! $analyzer instanceof FakeListingAiAnalyzer
                    && ! $matcher instanceof FakeCatalogProductMatcher;
            } catch (Throwable) {
                $providersValid = false;
            }

            $this->result(
                'providers.analysis',
                $providersValid,
                'Resolvable production analysis and product-matching providers are active.',
                'Enabled analysis submission requires resolvable non-fake analysis and product-matching providers.',
            );
        }

        $mailer = (string) config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");
        $from = trim((string) config('mail.from.address'));
        $mailValid = is_string($transport)
            && ! in_array($transport, ['array', 'log'], true)
            && filter_var($from, FILTER_VALIDATE_EMAIL) !== false
            && ! str_ends_with(strtolower($from), '@example.com')
            && ! str_ends_with(strtolower($from), '@example.test');

        $this->result(
            'providers.mail',
            $mailValid,
            'A real mail transport and non-placeholder sender are configured.',
            'Production mail cannot use log/array transport or an example sender address.',
        );
    }

    private function inspectFeatureDependencies(): void
    {
        $requests = (bool) config('broker.requests_enabled');
        $offers = (bool) config('broker.offers_enabled');
        $transactions = (bool) config('broker.transactions_enabled');
        $paymentCases = (bool) config('broker.payment_cases_enabled');
        $reports = (bool) config('broker.reports_enabled');
        $commissionValid = trim((string) config('broker.commission_rule_version')) !== ''
            && (int) config('broker.commission_rate_basis_points') >= 1
            && (int) config('broker.commission_rate_basis_points') <= 10_000;
        $brokerMonitoringValid = true;

        try {
            $this->brokerOperations->thresholds();
        } catch (Throwable) {
            $brokerMonitoringValid = false;
        }

        $brokerValid = (! $offers || $requests)
            && (! $transactions || ($offers && $commissionValid))
            && (! $paymentCases || $transactions)
            && (! $reports || $transactions)
            && $brokerMonitoringValid;

        $privacyValid = true;

        try {
            $this->privacy->workflowVersion();
            $this->privacy->privacyNoticeVersion();
            $this->privacy->responseTargetDays();
            $this->privacy->fulfillmentExecutionVersion();
            $this->privacy->dataInventoryVersion();
            $this->privacy->maximumExportBytes();
            $this->privacy->maximumArtifactRetentionDays();
            $this->privacy->erasureExecutionVersion();
            $this->privacy->erasureDataInventoryVersion();
            $this->privacy->maximumBackupRetentionDays();
        } catch (Throwable) {
            $privacyValid = false;
        }

        $this->result(
            'features.dependencies',
            $brokerValid && $privacyValid,
            'Feature switches and versioned privacy/broker dependencies are internally consistent.',
            'A feature switch dependency or versioned privacy/broker configuration is invalid.',
        );
    }

    private function inspectOptionalIntegrations(): void
    {
        $billingReady = true;

        if ($this->billing->checkoutEnabled()) {
            try {
                foreach ([PlanCode::Starter, PlanCode::Pro] as $plan) {
                    foreach (BillingInterval::cases() as $interval) {
                        $billingReady = $billingReady
                            && $this->billing->checkoutAvailable($plan, $interval);
                    }
                }
            } catch (Throwable) {
                $billingReady = false;
            }
        }

        if ($this->billing->checkoutEnabled() && $billingReady) {
            $this->pass(
                'optional.billing',
                'Hosted billing is enabled with complete provider and price configuration.',
            );
        } elseif ($this->billing->checkoutEnabled()) {
            $this->fail(
                'optional.billing',
                'Billing is enabled but provider, webhook, price, amount, or currency configuration is incomplete.',
            );
        } else {
            $this->warning(
                'optional.billing',
                'Hosted billing remains disabled pending the external Stripe activation record.',
            );
        }

        if ($this->telegram->isConfigured()) {
            $webhookOrigin = $this->httpsOrigin(
                (string) config('monitoring.telegram.webhook_url'),
                allowPath: true,
            );
            $appOrigin = $this->httpsOrigin((string) config('app.url'));

            $this->result(
                'optional.telegram',
                $webhookOrigin !== null
                    && $appOrigin !== null
                    && hash_equals($appOrigin, $webhookOrigin),
                'Telegram credentials and the same-origin HTTPS webhook are configured.',
                'Telegram is partially configured or its webhook is outside the production HTTPS origin.',
            );
        } else {
            $this->warning(
                'optional.telegram',
                'Telegram remains unavailable until all provider credentials and webhook controls are approved.',
            );
        }

        $disabled = [];

        foreach ([
            'analysis submission' => 'analyses.submission_enabled',
            'marketplace CSV import' => 'marketplace_connectors.csv_import_enabled',
            'manual analysis retry' => 'analyses.manual_retry_enabled',
            'privacy export completion' => 'privacy.fulfillment.enabled',
            'privacy erasure execution' => 'privacy.erasure.enabled',
            'broker offers' => 'broker.offers_enabled',
            'broker transactions' => 'broker.transactions_enabled',
            'broker payment cases' => 'broker.payment_cases_enabled',
            'broker reports' => 'broker.reports_enabled',
        ] as $label => $key) {
            if (! (bool) config($key)) {
                $disabled[] = $label;
            }
        }

        if ($disabled === []) {
            $this->pass(
                'optional.activation_switches',
                'All reviewed application feature switches are active.',
            );
        } else {
            $this->warning(
                'optional.activation_switches',
                'Disabled pending separate approval: '.implode(', ', $disabled).'.',
            );
        }
    }

    private function inspectReleaseArtifact(): void
    {
        $index = public_path('spa/index.html');

        if (is_file($index) && is_readable($index)) {
            $this->pass(
                'artifact.frontend',
                'The built Angular application shell is present and readable.',
            );
        } else {
            $this->warning(
                'artifact.frontend',
                'Run the production frontend build and serving verifier before activation.',
            );
        }
    }

    private function validEncryptionKey(): bool
    {
        $key = config('app.key');
        $cipher = config('app.cipher');

        if (! is_string($key) || ! is_string($cipher) || $key === '') {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false) {
                return false;
            }

            $key = $decoded;
        }

        return Encrypter::supported($key, $cipher);
    }

    private function validProxy(string $proxy): bool
    {
        $proxy = trim($proxy);

        if (in_array($proxy, ['*', '**', '0.0.0.0/0', '::/0'], true)) {
            return false;
        }

        if (filter_var($proxy, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $parts = explode('/', $proxy, 2);

        if (count($parts) !== 2) {
            return false;
        }

        $maximum = str_contains($parts[0], ':') ? 128 : 32;

        return filter_var($parts[0], FILTER_VALIDATE_IP) !== false
            && ctype_digit($parts[1])
            && (int) $parts[1] >= 0
            && (int) $parts[1] <= $maximum;
    }

    private function httpsOrigin(string $url, bool $allowPath = false): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (
                ! $allowPath
                && isset($parts['path'])
                && ! in_array($parts['path'], ['', '/'], true)
            )
        ) {
            return null;
        }

        $host = strtolower($parts['host']);

        if ($this->placeholderHost($host)) {
            return null;
        }

        $port = isset($parts['port']) && (int) $parts['port'] !== 443
            ? ':'.(int) $parts['port']
            : '';

        return "https://{$host}{$port}";
    }

    private function hostWithPort(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        return strtolower($parts['host']).(
            isset($parts['port']) ? ':'.(int) $parts['port'] : ''
        );
    }

    private function placeholderHost(string $host): bool
    {
        return $host === 'localhost'
            || $host === 'example.com'
            || $host === 'example.test'
            || str_ends_with($host, '.example.com')
            || str_ends_with($host, '.example.test');
    }

    private function configuredString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function result(
        string $key,
        bool $passed,
        string $passedMessage,
        string $failedMessage,
    ): void {
        if ($passed) {
            $this->pass($key, $passedMessage);

            return;
        }

        $this->fail($key, $failedMessage);
    }

    private function pass(string $key, string $message): void
    {
        $this->checks[] = new ProductionPreflightCheck(
            $key,
            ProductionPreflightCheck::PASS,
            $message,
        );
    }

    private function warning(string $key, string $message): void
    {
        $this->checks[] = new ProductionPreflightCheck(
            $key,
            ProductionPreflightCheck::WARNING,
            $message,
        );
    }

    private function fail(string $key, string $message): void
    {
        $this->checks[] = new ProductionPreflightCheck(
            $key,
            ProductionPreflightCheck::FAIL,
            $message,
        );
    }
}
