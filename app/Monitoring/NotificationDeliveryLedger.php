<?php

namespace App\Monitoring;

use App\Enums\Monitoring\NotificationChannel;
use App\Enums\Monitoring\NotificationEventType;
use App\Models\Alert;
use App\Models\NotificationLog;
use Closure;
use Illuminate\Support\Facades\DB;

final class NotificationDeliveryLedger
{
    /**
     * @param  Closure(Alert, NotificationLog): (?string)  $eligibilityReason
     */
    public function beginAttempt(
        string $alertId,
        NotificationChannel $channel,
        string $provider,
        int $attempt,
        string $executionId,
        int $staleAfterSeconds,
        Closure $eligibilityReason,
    ): ?NotificationLog {
        return DB::transaction(function () use (
            $alertId,
            $channel,
            $provider,
            $attempt,
            $executionId,
            $staleAfterSeconds,
            $eligibilityReason,
        ): ?NotificationLog {
            $alert = Alert::query()->lockForUpdate()->find($alertId);

            if ($alert === null) {
                return null;
            }

            $current = $this->current($alert, $channel);

            if (
                $current === null
                || $current->event_type->isTerminalDelivery()
            ) {
                return null;
            }

            if ($current->event_type === NotificationEventType::Attempting) {
                if (
                    $current->occurred_at->greaterThan(
                        now()->subSeconds($staleAfterSeconds),
                    )
                ) {
                    return null;
                }

                $this->append(
                    $alert,
                    $current,
                    $channel,
                    NotificationEventType::Exhausted,
                    [
                        'provider' => $provider,
                        'reason_code' => 'stale_attempt_outcome_unknown',
                        'previous_execution_id' => (
                            $current->payload['delivery']['execution_id']
                            ?? null
                        ),
                    ],
                );

                return null;
            }

            if (
                $current->event_type === NotificationEventType::Failed
                && ($current->payload['delivery']['will_retry'] ?? false)
                    !== true
            ) {
                $this->append(
                    $alert,
                    $current,
                    $channel,
                    NotificationEventType::Exhausted,
                    [
                        'provider' => $provider,
                        'reason_code' => 'delivery_attempts_exhausted',
                    ],
                );

                return null;
            }

            $reasonCode = $eligibilityReason($alert, $current);

            if ($reasonCode !== null) {
                $this->append(
                    $alert,
                    $current,
                    $channel,
                    NotificationEventType::Suppressed,
                    [
                        'provider' => $provider,
                        'reason_code' => $reasonCode,
                    ],
                );

                return null;
            }

            return $this->append(
                $alert,
                $current,
                $channel,
                NotificationEventType::Attempting,
                [
                    'provider' => $provider,
                    'attempt' => $attempt,
                    'execution_id' => $executionId,
                ],
            );
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordDelivered(
        string $alertId,
        NotificationLog $attemptLog,
        NotificationChannel $channel,
        string $provider,
        int $attempt,
        string $executionId,
        array $metadata = [],
    ): void {
        DB::transaction(function () use (
            $alertId,
            $attemptLog,
            $channel,
            $provider,
            $attempt,
            $executionId,
            $metadata,
        ): void {
            $alert = Alert::query()->lockForUpdate()->find($alertId);

            if ($alert === null) {
                return;
            }

            $current = $this->current($alert, $channel);

            if ($current?->getKey() !== $attemptLog->getKey()) {
                return;
            }

            $this->append(
                $alert,
                $current,
                $channel,
                NotificationEventType::Delivered,
                [
                    'provider' => $provider,
                    'attempt' => $attempt,
                    'execution_id' => $executionId,
                    ...$metadata,
                ],
            );
        }, attempts: 3);
    }

    public function recordFailure(
        string $alertId,
        NotificationLog $attemptLog,
        NotificationChannel $channel,
        string $provider,
        int $attempt,
        int $maximumAttempts,
        string $executionId,
        string $exceptionClass,
        string $exceptionSummary,
        array $retryDelays,
    ): void {
        DB::transaction(function () use (
            $alertId,
            $attemptLog,
            $channel,
            $provider,
            $attempt,
            $maximumAttempts,
            $executionId,
            $exceptionClass,
            $exceptionSummary,
            $retryDelays,
        ): void {
            $alert = Alert::query()->lockForUpdate()->find($alertId);

            if ($alert === null) {
                return;
            }

            $current = $this->current($alert, $channel);

            if ($current?->getKey() !== $attemptLog->getKey()) {
                return;
            }

            $willRetry = $attempt < $maximumAttempts;
            $delays = array_values(array_map('intval', $retryDelays));

            if ($delays === []) {
                $delays = [60];
            }

            $delay = $willRetry
                ? $delays[min($attempt - 1, count($delays) - 1)]
                : null;

            $this->append(
                $alert,
                $current,
                $channel,
                NotificationEventType::Failed,
                [
                    'provider' => $provider,
                    'attempt' => $attempt,
                    'execution_id' => $executionId,
                    'exception_class' => $exceptionClass,
                    'exception_summary' => $exceptionSummary,
                    'will_retry' => $willRetry,
                    'next_retry_seconds' => $delay,
                ],
            );
        }, attempts: 3);
    }

    public function markExhausted(
        string $alertId,
        NotificationChannel $channel,
        string $provider,
        ?string $exceptionClass,
        ?string $exceptionSummary,
    ): void {
        DB::transaction(function () use (
            $alertId,
            $channel,
            $provider,
            $exceptionClass,
            $exceptionSummary,
        ): void {
            $alert = Alert::query()->lockForUpdate()->find($alertId);

            if ($alert === null) {
                return;
            }

            $current = $this->current($alert, $channel);

            if (
                $current === null
                || $current->event_type->isTerminalDelivery()
            ) {
                return;
            }

            $this->append(
                $alert,
                $current,
                $channel,
                NotificationEventType::Exhausted,
                [
                    'provider' => $provider,
                    'reason_code' => 'delivery_attempts_exhausted',
                    'exception_class' => $exceptionClass,
                    'exception_summary' => $exceptionSummary,
                ],
            );
        }, attempts: 3);
    }

    private function current(
        Alert $alert,
        NotificationChannel $channel,
    ): ?NotificationLog {
        return NotificationLog::query()
            ->where('alert_id', $alert->getKey())
            ->where('channel', $channel)
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $delivery
     */
    private function append(
        Alert $alert,
        NotificationLog $previous,
        NotificationChannel $channel,
        NotificationEventType $eventType,
        array $delivery,
    ): NotificationLog {
        $previousDelivery = $previous->payload['delivery'] ?? [];

        return NotificationLog::query()->create([
            'organization_id' => $alert->organization_id,
            'alert_id' => $alert->getKey(),
            'recipient_user_id' => $alert->recipient_user_id,
            'previous_log_id' => $previous->getKey(),
            'channel' => $channel,
            'event_type' => $eventType,
            'sequence' => $previous->sequence + 1,
            'payload' => [
                ...$previous->payload,
                'delivery' => [
                    ...(is_array($previousDelivery)
                        ? $previousDelivery
                        : []),
                    ...$delivery,
                ],
            ],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
