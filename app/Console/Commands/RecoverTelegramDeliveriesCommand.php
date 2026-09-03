<?php

namespace App\Console\Commands;

use App\Enums\Monitoring\NotificationChannel;
use App\Jobs\Monitoring\SendAlertTelegram;
use App\Monitoring\RecoverNotificationDeliveryHeads;
use Illuminate\Console\Command;

final class RecoverTelegramDeliveriesCommand extends Command
{
    protected $signature = 'notifications:recover-telegram-deliveries {--limit=100}';

    protected $description = 'Redispatch orphaned retryable Telegram delivery ledger heads.';

    public function handle(
        RecoverNotificationDeliveryHeads $recovery,
    ): int {
        $count = $recovery->recover(
            NotificationChannel::Telegram,
            (int) $this->option('limit'),
            (int) config(
                'monitoring.telegram.delivery.recovery_chunk_size',
                100,
            ),
            (int) config(
                'monitoring.telegram.delivery.recovery_after_seconds',
                120,
            ),
            static fn (string $alertId) => SendAlertTelegram::dispatch(
                $alertId,
            ),
        );

        $this->info(
            "Redispatched {$count} Telegram delivery records.",
        );

        return self::SUCCESS;
    }
}
