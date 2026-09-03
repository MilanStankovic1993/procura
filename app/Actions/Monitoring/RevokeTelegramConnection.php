<?php

namespace App\Actions\Monitoring;

use App\Enums\Monitoring\TelegramConnectionEventType;
use App\Enums\Monitoring\TelegramConnectionStatus;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Monitoring\Telegram\TelegramConnectionEvents;
use Illuminate\Support\Facades\DB;

final class RevokeTelegramConnection
{
    public function __construct(
        private readonly TelegramConnectionEvents $events,
    ) {}

    public function revoke(User $user): ?TelegramConnection
    {
        return DB::transaction(function () use ($user): ?TelegramConnection {
            User::query()->lockForUpdate()->findOrFail($user->getKey());
            $connection = TelegramConnection::query()
                ->where('user_id', $user->getKey())
                ->whereIn('status', [
                    TelegramConnectionStatus::Pending,
                    TelegramConnectionStatus::Connected,
                ])
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                return null;
            }

            $telegramUserHash = $connection->telegram_user_id_hash;
            $chatHash = $connection->chat_id_hash;
            $connection->forceFill([
                'status' => TelegramConnectionStatus::Revoked,
                'challenge_token' => null,
                'telegram_user_id' => null,
                'telegram_user_id_hash' => null,
                'chat_id' => null,
                'chat_id_hash' => null,
                'username' => null,
                'revoked_at' => now(),
            ])->save();
            $this->events->append(
                $connection,
                TelegramConnectionEventType::Revoked,
                [
                    'provider' => $connection->provider,
                    'reason_code' => 'user_requested',
                    'telegram_user_id_hash' => $telegramUserHash,
                    'chat_id_hash' => $chatHash,
                ],
            );

            return $connection;
        }, attempts: 3);
    }
}
