<?php

namespace App\Operations\Dashboard;

use App\Analysis\Operations\AnalysisOperationsQuery;
use App\BrokerRequests\Operations\BrokerOperationsMonitor;
use App\Enums\Monitoring\NotificationChannel;
use App\Enums\Monitoring\NotificationEventType;
use App\Enums\Subscriptions\FeatureCode;
use App\Models\BillingProviderEvent;
use App\Models\Country;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\OrganizationPlanAssignment;
use App\Models\PrivacyRequest;
use App\Models\SubscriptionUsage;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Operations\OperationsConfiguration;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

final class PlatformOverviewMetrics
{
    private const METRIC_KEYS = [
        'users',
        'organizations',
        'subscriptions',
        'analyses',
        'markets',
        'telegram_connections',
        'failed_notifications',
        'failed_telegram_notifications',
        'billing_attention',
        'privacy_requests',
        'analysis_operations',
        'broker_operations',
    ];

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly OperationsConfiguration $configuration,
        private readonly AnalysisOperationsQuery $analysisOperations,
        private readonly BrokerOperationsMonitor $brokerOperations,
    ) {}

    /**
     * @return array<string, int>
     */
    public function snapshot(): array
    {
        try {
            $repository = $this->repository();
            $cacheKey = $this->configuration
                ->dashboardMetricsCacheKey();
            $cached = $repository->get($cacheKey);

            if ($this->isValidPayload($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            return $this->uncached();
        }

        $metricsQueryInProgress = false;
        $computedMetrics = null;

        try {
            return $repository
                ->lock(
                    "{$cacheKey}:lock",
                    $this->configuration
                        ->dashboardMetricsLockSeconds(),
                )
                ->block(
                    $this->configuration
                        ->dashboardMetricsLockWaitSeconds(),
                    function () use (
                        $repository,
                        $cacheKey,
                        &$metricsQueryInProgress,
                        &$computedMetrics,
                    ): array {
                        try {
                            $cached = $repository->get($cacheKey);

                            if ($this->isValidPayload($cached)) {
                                return $cached;
                            }
                        } catch (Throwable) {
                            $metricsQueryInProgress = true;
                            $computedMetrics = $this->uncached();
                            $metricsQueryInProgress = false;

                            return $computedMetrics;
                        }

                        $metricsQueryInProgress = true;
                        $computedMetrics = $this->uncached();
                        $metricsQueryInProgress = false;

                        try {
                            $repository->put(
                                $cacheKey,
                                $computedMetrics,
                                $this->configuration
                                    ->dashboardMetricsTtlSeconds(),
                            );
                        } catch (Throwable) {
                            // Metrics remain usable when only cache persistence fails.
                        }

                        return $computedMetrics;
                    },
                );
        } catch (LockTimeoutException) {
            return $this->uncached();
        } catch (Throwable $exception) {
            if ($metricsQueryInProgress) {
                throw $exception;
            }

            if ($computedMetrics !== null) {
                return $computedMetrics;
            }

            return $this->uncached();
        }
    }

    /**
     * @return array<string, int>
     */
    public function uncached(): array
    {
        $monthStart = now('UTC')->startOfMonth()->toDateString();

        return [
            'users' => User::query()->count(),
            'organizations' => Organization::query()->count(),
            'subscriptions' => OrganizationPlanAssignment::query()
                ->whereNull('ends_at')
                ->count(),
            'analyses' => (int) SubscriptionUsage::query()
                ->where('feature_code', FeatureCode::MonthlyAnalyses)
                ->where('period_start', $monthStart)
                ->sum('used'),
            'markets' => Country::query()
                ->where('active', true)
                ->count(),
            'telegram_connections' => TelegramConnection::query()
                ->connected()
                ->count(),
            'failed_notifications' => $this->failedDeliveryCount(
                NotificationChannel::Email,
            ),
            'failed_telegram_notifications' => (
                $this->failedDeliveryCount(
                    NotificationChannel::Telegram,
                )
            ),
            'billing_attention' => BillingProviderEvent::query()
                ->where(function ($query): void {
                    $query
                        ->where('outcome', 'rejected')
                        ->orWhere(
                            'reason_code',
                            'active_subscription_conflict',
                        );
                })
                ->count(),
            'privacy_requests' => PrivacyRequest::query()
                ->whereNotIn(
                    'status',
                    ['fulfilled', 'rejected', 'cancelled'],
                )
                ->count(),
            'analysis_operations' => $this->analysisOperations->count(),
            'broker_operations' => $this->brokerOperations->attentionCount(),
        ];
    }

    private function repository(): Repository
    {
        return $this->cache->store(
            $this->configuration->dashboardMetricsCacheStore(),
        );
    }

    private function failedDeliveryCount(
        NotificationChannel $channel,
    ): int {
        return NotificationLog::query()
            ->where('channel', $channel)
            ->whereIn('event_type', [
                NotificationEventType::Failed,
                NotificationEventType::Exhausted,
            ])
            ->whereNotExists(function ($query) use ($channel): void {
                $query
                    ->selectRaw('1')
                    ->from('notification_logs as newer_logs')
                    ->whereColumn(
                        'newer_logs.alert_id',
                        'notification_logs.alert_id',
                    )
                    ->where(
                        'newer_logs.channel',
                        $channel->value,
                    )
                    ->whereColumn(
                        'newer_logs.sequence',
                        '>',
                        'notification_logs.sequence',
                    );
            })
            ->count();
    }

    private function isValidPayload(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        foreach (self::METRIC_KEYS as $key) {
            if (
                ! array_key_exists($key, $payload)
                || ! is_int($payload[$key])
                || $payload[$key] < 0
            ) {
                return false;
            }
        }

        return count($payload) === count(self::METRIC_KEYS);
    }
}
