<?php

namespace App\Monitoring\Telegram;

final class TelegramConfiguration
{
    public function isConfigured(): bool
    {
        return $this->botToken() !== ''
            && $this->botUsername() !== ''
            && $this->webhookSecret() !== ''
            && $this->identityHashKey() !== '';
    }

    public function provider(): string
    {
        return (string) config(
            'monitoring.telegram.provider',
            'telegram-bot-api',
        );
    }

    public function botToken(): string
    {
        return trim((string) config('monitoring.telegram.bot_token'));
    }

    public function botUsername(): string
    {
        return ltrim(
            trim((string) config('monitoring.telegram.bot_username')),
            '@',
        );
    }

    public function webhookSecret(): string
    {
        return trim((string) config('monitoring.telegram.webhook_secret'));
    }

    public function identityHashKey(): string
    {
        return trim((string) config(
            'monitoring.telegram.identity_hash_key',
        ));
    }

    public function challengeTtlMinutes(): int
    {
        return max(
            5,
            min(
                60,
                (int) config(
                    'monitoring.telegram.connection_challenge_ttl_minutes',
                    15,
                ),
            ),
        );
    }
}
