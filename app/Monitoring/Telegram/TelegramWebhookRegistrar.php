<?php

namespace App\Monitoring\Telegram;

use App\Monitoring\Telegram\Exceptions\TelegramProviderException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TelegramWebhookRegistrar
{
    public function __construct(
        private readonly TelegramConfiguration $configuration,
    ) {}

    public function register(string $url): void
    {
        if (! $this->configuration->isConfigured()) {
            throw new TelegramProviderException(
                'telegram_provider_not_configured',
            );
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(20)
                ->post(
                    'https://api.telegram.org/bot'
                        .$this->configuration->botToken()
                        .'/setWebhook',
                    [
                        'url' => $url,
                        'secret_token' => (
                            $this->configuration->webhookSecret()
                        ),
                        'allowed_updates' => ['message'],
                        'drop_pending_updates' => false,
                        'max_connections' => 40,
                    ],
                );
        } catch (Throwable) {
            throw new TelegramProviderException(
                'telegram_webhook_transport_failed',
            );
        }

        if (
            ! $response->successful()
            || $response->json('ok') !== true
        ) {
            throw new TelegramProviderException(
                'telegram_webhook_rejected',
                $response->status(),
            );
        }
    }
}
