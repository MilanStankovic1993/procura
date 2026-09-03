<?php

namespace App\Console\Commands;

use App\Enums\Monitoring\NotificationChannel;
use App\Jobs\Monitoring\SendAlertEmail;
use App\Monitoring\RecoverNotificationDeliveryHeads;
use Illuminate\Console\Command;

class RecoverEmailDeliveriesCommand extends Command
{
    protected $signature = 'notifications:recover-email-deliveries {--limit=100}';

    protected $description = 'Redispatch orphaned retryable email delivery ledger heads.';

    public function handle(
        RecoverNotificationDeliveryHeads $recovery,
    ): int {
        $count = $recovery->recover(
            NotificationChannel::Email,
            (int) $this->option('limit'),
            (int) config(
                'monitoring.email_delivery.recovery_chunk_size',
                100,
            ),
            (int) config(
                'monitoring.email_delivery.recovery_after_seconds',
                120,
            ),
            static fn (string $alertId) => SendAlertEmail::dispatch(
                $alertId,
            ),
        );

        $this->info(
            "Redispatched {$count} email delivery records.",
        );

        return self::SUCCESS;
    }
}
