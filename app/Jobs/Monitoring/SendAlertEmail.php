<?php

namespace App\Jobs\Monitoring;

use App\Actions\Monitoring\DeliverAlertEmail;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendAlertEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $alertId,
    ) {
        $this->tries = (int) config(
            'monitoring.email_delivery.tries',
            4,
        );
        $this->timeout = (int) config(
            'monitoring.email_delivery.timeout_seconds',
            30,
        );
        $this->onQueue((string) config(
            'monitoring.email_delivery.queue',
            'notifications',
        ));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_map(
            'intval',
            (array) config(
                'monitoring.email_delivery.retry_delays_seconds',
                [60, 300, 900],
            ),
        );
    }

    public function uniqueId(): string
    {
        return $this->alertId;
    }

    public function handle(DeliverAlertEmail $delivery): void
    {
        $delivery->deliver(
            $this->alertId,
            max(1, $this->attempts()),
            $this->tries,
        );
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(DeliverAlertEmail::class)->markExhausted(
                $this->alertId,
                $exception,
            );
        } catch (Throwable $recordingException) {
            report($recordingException);
        }
    }
}
