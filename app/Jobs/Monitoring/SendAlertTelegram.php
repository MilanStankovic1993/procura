<?php

namespace App\Jobs\Monitoring;

use App\Actions\Monitoring\DeliverAlertTelegram;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SendAlertTelegram implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $alertId,
    ) {
        $this->tries = (int) config(
            'monitoring.telegram.delivery.tries',
            4,
        );
        $this->timeout = (int) config(
            'monitoring.telegram.delivery.timeout_seconds',
            20,
        );
        $this->onQueue((string) config(
            'monitoring.telegram.delivery.queue',
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
                'monitoring.telegram.delivery.retry_delays_seconds',
                [30, 120, 600],
            ),
        );
    }

    public function uniqueId(): string
    {
        return $this->alertId;
    }

    public function handle(DeliverAlertTelegram $delivery): void
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
            app(DeliverAlertTelegram::class)->markExhausted(
                $this->alertId,
                $exception,
            );
        } catch (Throwable $recordingException) {
            report($recordingException);
        }
    }
}
