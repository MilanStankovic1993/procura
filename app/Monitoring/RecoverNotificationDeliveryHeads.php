<?php

namespace App\Monitoring;

use App\Enums\Monitoring\NotificationChannel;
use App\Enums\Monitoring\NotificationEventType;
use App\Models\NotificationLog;
use Closure;

final class RecoverNotificationDeliveryHeads
{
    /**
     * @param  Closure(string): void  $dispatch
     */
    public function recover(
        NotificationChannel $channel,
        int $requestedLimit,
        int $configuredMaximum,
        int $recoveryAfterSeconds,
        Closure $dispatch,
    ): int {
        $limit = max(1, min($configuredMaximum, $requestedLimit));
        $recoverBefore = now()->subSeconds($recoveryAfterSeconds);
        $logs = NotificationLog::query()
            ->where('channel', $channel)
            ->where('occurred_at', '<=', $recoverBefore)
            ->where(function ($query): void {
                $query
                    ->where(
                        'event_type',
                        NotificationEventType::Queued,
                    )
                    ->orWhere(function ($query): void {
                        $query
                            ->where(
                                'event_type',
                                NotificationEventType::Failed,
                            )
                            ->where(
                                'payload->delivery->will_retry',
                                true,
                            );
                    });
            })
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
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($logs as $log) {
            $dispatch($log->alert_id);
        }

        return $logs->count();
    }
}
