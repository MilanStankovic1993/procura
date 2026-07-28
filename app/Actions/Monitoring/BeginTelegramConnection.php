<?php

namespace App\Actions\Monitoring;

use App\Enums\Monitoring\TelegramConnectionEventType;
use App\Enums\Monitoring\TelegramConnectionStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Monitoring\Telegram\TelegramConfiguration;
use App\Monitoring\Telegram\TelegramConnectionEvents;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\DB;

final class BeginTelegramConnection
{
    public function __construct(
        private readonly TelegramConfiguration $configuration,
        private readonly TelegramConnectionEvents $events,
    ) {}

    public function begin(User $user): TelegramConnection
    {
        if (! $this->configuration->isConfigured()) {
            ApplicationValidation::fail(
                'telegram',
                ApplicationValidationCode::TelegramNotConfigured,
            );
        }

        return DB::transaction(function () use ($user): TelegramConnection {
            User::query()->lockForUpdate()->findOrFail($user->getKey());
            $current = TelegramConnection::query()
                ->where('user_id', $user->getKey())
                ->whereIn('status', [
                    TelegramConnectionStatus::Pending,
                    TelegramConnectionStatus::Connected,
                ])
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if (
                $current?->status === TelegramConnectionStatus::Connected
            ) {
                return $current;
            }

            if (
                $current?->status === TelegramConnectionStatus::Pending
                && $current->challenge_expires_at->isFuture()
                && is_string($current->challenge_token)
                && $current->challenge_token !== ''
            ) {
                return $current;
            }

            if ($current !== null) {
                $current->forceFill([
                    'status' => TelegramConnectionStatus::Expired,
                    'challenge_token' => null,
                ])->save();
                $this->events->append(
                    $current,
                    TelegramConnectionEventType::Expired,
                    [
                        'provider' => $current->provider,
                        'reason_code' => 'connection_challenge_expired',
                    ],
                );
            }

            $token = $this->token();
            $connection = TelegramConnection::query()->create([
                'user_id' => $user->getKey(),
                'provider' => $this->configuration->provider(),
                'status' => TelegramConnectionStatus::Pending,
                'challenge_token' => $token,
                'challenge_token_hash' => hash('sha256', $token),
                'challenge_expires_at' => now()->addMinutes(
                    $this->configuration->challengeTtlMinutes(),
                ),
                'bot_username' => $this->configuration->botUsername(),
            ]);
            $this->events->append(
                $connection,
                TelegramConnectionEventType::Pending,
                [
                    'provider' => $connection->provider,
                    'bot_username' => $connection->bot_username,
                    'expires_at' => (
                        $connection->challenge_expires_at->toIso8601String()
                    ),
                ],
            );

            return $connection;
        }, attempts: 3);
    }

    private function token(): string
    {
        return rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '=',
        );
    }
}
