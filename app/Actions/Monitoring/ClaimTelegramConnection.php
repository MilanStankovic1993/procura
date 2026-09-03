<?php

namespace App\Actions\Monitoring;

use App\Enums\Monitoring\TelegramConnectionEventType;
use App\Enums\Monitoring\TelegramConnectionStatus;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Monitoring\Telegram\TelegramConnectionEvents;
use App\Monitoring\Telegram\TelegramIdentityHasher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ClaimTelegramConnection
{
    public function __construct(
        private readonly TelegramConnectionEvents $events,
        private readonly TelegramIdentityHasher $identityHasher,
    ) {}

    public function claim(
        string $token,
        string $telegramUserId,
        string $chatId,
        ?string $username,
        int $updateId,
    ): bool {
        $tokenHash = hash('sha256', $token);
        $candidate = TelegramConnection::query()
            ->where('challenge_token_hash', $tokenHash)
            ->first();

        if ($candidate === null) {
            return false;
        }

        try {
            return DB::transaction(function () use (
                $candidate,
                $tokenHash,
                $telegramUserId,
                $chatId,
                $username,
                $updateId,
            ): bool {
                User::query()
                    ->lockForUpdate()
                    ->findOrFail($candidate->user_id);
                $connection = TelegramConnection::query()
                    ->whereKey($candidate->getKey())
                    ->where('challenge_token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();

                if (
                    $connection === null
                    || $connection->status
                        !== TelegramConnectionStatus::Pending
                ) {
                    return false;
                }

                if ($connection->challenge_expires_at->isPast()) {
                    $connection->forceFill([
                        'status' => TelegramConnectionStatus::Expired,
                        'challenge_token' => null,
                    ])->save();
                    $this->events->append(
                        $connection,
                        TelegramConnectionEventType::Expired,
                        [
                            'provider' => $connection->provider,
                            'reason_code' => 'connection_challenge_expired',
                        ],
                    );

                    return false;
                }

                $telegramUserHash = $this->identityHasher->hash(
                    $telegramUserId,
                );
                $chatHash = $this->identityHasher->hash($chatId);
                $claimedByAnotherUser = TelegramConnection::query()
                    ->connected()
                    ->where('user_id', '!=', $connection->user_id)
                    ->where(function ($query) use (
                        $telegramUserHash,
                        $chatHash,
                    ): void {
                        $query
                            ->where(
                                'telegram_user_id_hash',
                                $telegramUserHash,
                            )
                            ->orWhere('chat_id_hash', $chatHash);
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($claimedByAnotherUser) {
                    return false;
                }

                $connection->forceFill([
                    'status' => TelegramConnectionStatus::Connected,
                    'challenge_token' => null,
                    'telegram_user_id' => $telegramUserId,
                    'telegram_user_id_hash' => $telegramUserHash,
                    'chat_id' => $chatId,
                    'chat_id_hash' => $chatHash,
                    'username' => $username,
                    'connected_at' => now(),
                ])->save();
                $this->events->append(
                    $connection,
                    TelegramConnectionEventType::Connected,
                    [
                        'provider' => $connection->provider,
                        'telegram_update_id' => $updateId,
                        'telegram_user_id_hash' => $telegramUserHash,
                        'chat_id_hash' => $chatHash,
                    ],
                );

                return true;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
