<?php

namespace App\Monitoring\Telegram;

use App\Enums\Monitoring\TelegramConnectionEventType;
use App\Models\TelegramConnection;
use App\Models\TelegramConnectionEvent;

final class TelegramConnectionEvents
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function append(
        TelegramConnection $connection,
        TelegramConnectionEventType $eventType,
        array $payload,
    ): TelegramConnectionEvent {
        $previous = TelegramConnectionEvent::query()
            ->where('telegram_connection_id', $connection->getKey())
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();

        return TelegramConnectionEvent::query()->create([
            'telegram_connection_id' => $connection->getKey(),
            'user_id' => $connection->user_id,
            'previous_event_id' => $previous?->getKey(),
            'event_type' => $eventType,
            'sequence' => ($previous?->sequence ?? 0) + 1,
            'payload' => $payload,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
