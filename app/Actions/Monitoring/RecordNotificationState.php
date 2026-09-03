<?php

namespace App\Actions\Monitoring;

use App\Enums\Monitoring\NotificationChannel;
use App\Enums\Monitoring\NotificationEventType;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Alert;
use App\Models\NotificationLog;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\DB;

class RecordNotificationState
{
    public function record(
        Alert $alert,
        User $actor,
        NotificationEventType $eventType,
        string $expectedCurrentLogId,
        string $idempotencyKey,
    ): NotificationLog {
        if (! in_array(
            $eventType,
            [
                NotificationEventType::Read,
                NotificationEventType::Unread,
                NotificationEventType::Archived,
            ],
            true,
        )) {
            ApplicationValidation::fail(
                'event_type',
                ApplicationValidationCode::SystemOwnedNotificationEvent,
            );
        }

        return DB::transaction(function () use (
            $alert,
            $actor,
            $eventType,
            $expectedCurrentLogId,
            $idempotencyKey,
        ): NotificationLog {
            $lockedAlert = Alert::query()
                ->forOrganization($alert->organization_id)
                ->where('recipient_user_id', $actor->getKey())
                ->lockForUpdate()
                ->findOrFail($alert->getKey());
            $existing = NotificationLog::query()
                ->forOrganization($lockedAlert->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if (
                    ($existing->payload['command_event_type'] ?? null)
                        !== $eventType->value
                    || ($existing->payload['expected_current_log_id'] ?? null)
                        !== $expectedCurrentLogId
                ) {
                    ApplicationValidation::fail(
                        'idempotency_key',
                        ApplicationValidationCode::NotificationIdempotencyConflict,
                    );
                }

                return $existing;
            }

            $current = NotificationLog::query()
                ->where('alert_id', $lockedAlert->getKey())
                ->where('channel', NotificationChannel::InApp)
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->firstOrFail();

            if ($current->getKey() !== $expectedCurrentLogId) {
                ApplicationValidation::fail(
                    'expected_current_log_id',
                    ApplicationValidationCode::NotificationStale,
                );
            }

            if ($current->event_type === NotificationEventType::Archived) {
                ApplicationValidation::fail(
                    'event_type',
                    ApplicationValidationCode::NotificationArchived,
                );
            }

            if ($current->event_type === $eventType) {
                return $current;
            }

            return NotificationLog::query()->create([
                'organization_id' => $lockedAlert->organization_id,
                'alert_id' => $lockedAlert->getKey(),
                'recipient_user_id' => $actor->getKey(),
                'actor_user_id' => $actor->getKey(),
                'previous_log_id' => $current->getKey(),
                'channel' => NotificationChannel::InApp,
                'event_type' => $eventType,
                'sequence' => $current->sequence + 1,
                'idempotency_key' => $idempotencyKey,
                'payload' => [
                    ...$lockedAlert->payload,
                    'command_event_type' => $eventType->value,
                    'expected_current_log_id' => $expectedCurrentLogId,
                ],
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }, attempts: 3);
    }
}
