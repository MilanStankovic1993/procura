<?php

namespace App\Filament\Widgets;

use App\Operations\Dashboard\PlatformOverviewMetrics;
use App\Operations\OperationalReadiness;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $metrics = app(PlatformOverviewMetrics::class)->snapshot();
        $readiness = app(OperationalReadiness::class)->inspect();
        $readinessState = match (true) {
            ! $readiness->ready => 'unavailable',
            $readiness->checks['queues'] === 'not_monitored' => 'core_ready',
            default => 'ready',
        };

        return [
            Stat::make(
                __('admin.dashboard.stats.operational_readiness.label'),
                __(
                    "admin.values.operational_readiness_status.{$readinessState}",
                ),
            )
                ->description(
                    __(
                        "admin.dashboard.stats.operational_readiness.description_{$readinessState}",
                    ),
                )
                ->color(match ($readinessState) {
                    'ready' => 'success',
                    'core_ready' => 'warning',
                    default => 'danger',
                }),
            Stat::make(
                __('admin.dashboard.stats.users.label'),
                $metrics['users'],
            )
                ->description(__('admin.dashboard.stats.users.description')),
            Stat::make(
                __('admin.dashboard.stats.organizations.label'),
                $metrics['organizations'],
            )
                ->description(__('admin.dashboard.stats.organizations.description')),
            Stat::make(
                __('admin.dashboard.stats.subscriptions.label'),
                $metrics['subscriptions'],
            )
                ->description(__('admin.dashboard.stats.subscriptions.description')),
            Stat::make(
                __('admin.dashboard.stats.analyses.label'),
                $metrics['analyses'],
            )
                ->description(__('admin.dashboard.stats.analyses.description')),
            Stat::make(
                __('admin.dashboard.stats.markets.label'),
                $metrics['markets'],
            )
                ->description(__('admin.dashboard.stats.markets.description')),
            Stat::make(
                __('admin.dashboard.stats.telegram_connections.label'),
                $metrics['telegram_connections'],
            )->description(
                __('admin.dashboard.stats.telegram_connections.description'),
            ),
            Stat::make(
                __('admin.dashboard.stats.failed_notifications.label'),
                $metrics['failed_notifications'],
            )->description(
                __('admin.dashboard.stats.failed_notifications.description'),
            ),
            Stat::make(
                __('admin.dashboard.stats.failed_telegram_notifications.label'),
                $metrics['failed_telegram_notifications'],
            )->description(
                __(
                    'admin.dashboard.stats.failed_telegram_notifications.description',
                ),
            ),
            Stat::make(
                __('admin.dashboard.stats.billing_attention.label'),
                $metrics['billing_attention'],
            )->description(
                __('admin.dashboard.stats.billing_attention.description'),
            ),
            Stat::make(
                __('admin.dashboard.stats.privacy_requests.label'),
                $metrics['privacy_requests'],
            )
                ->description(
                    __('admin.dashboard.stats.privacy_requests.description'),
                )
                ->color(
                    $metrics['privacy_requests'] > 0
                        ? 'warning'
                        : 'success',
                ),
            Stat::make(
                __('admin.dashboard.stats.analysis_operations.label'),
                $metrics['analysis_operations'],
            )
                ->description(
                    __(
                        'admin.dashboard.stats.analysis_operations.description',
                    ),
                )
                ->color(
                    $metrics['analysis_operations'] > 0
                        ? 'danger'
                        : 'success',
                ),
        ];
    }
}
