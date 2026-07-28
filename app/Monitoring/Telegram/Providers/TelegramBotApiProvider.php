<?php

namespace App\Monitoring\Telegram\Providers;

use App\Models\TelegramConnection;
use App\Monitoring\Telegram\Contracts\TelegramProvider;
use App\Monitoring\Telegram\Data\TelegramDeliveryResult;
use App\Monitoring\Telegram\Exceptions\TelegramProviderException;
use App\Monitoring\Telegram\TelegramConfiguration;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TelegramBotApiProvider implements TelegramProvider
{
    public function __construct(
        private readonly TelegramConfiguration $configuration,
    ) {}

    public function sendMessage(
        TelegramConnection $connection,
        string $message,
        string $actionLabel,
        string $actionUrl,
    ): TelegramDeliveryResult {
        if (
            ! $this->configuration->isConfigured()
            || ! is_string($connection->chat_id)
            || $connection->chat_id === ''
        ) {
            throw new TelegramProviderException(
                'telegram_provider_not_configured',
            );
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config(
                    'monitoring.telegram.delivery.timeout_seconds',
                    20,
                ))
                ->post(
                    'https://api.telegram.org/bot'
                        .$this->configuration->botToken()
                        .'/sendMessage',
                    [
                        'chat_id' => $connection->chat_id,
                        'text' => mb_substr($message, 0, 4096),
                        'disable_web_page_preview' => true,
                        'reply_markup' => [
                            'inline_keyboard' => [[
                                [
                                    'text' => mb_substr(
                                        $actionLabel,
                                        0,
                                        64,
                                    ),
                                    'url' => $actionUrl,
                                ],
                            ]],
                        ],
                    ],
                );
        } catch (Throwable) {
            throw new TelegramProviderException(
                'telegram_provider_transport_failed',
            );
        }

        if (! $response->successful()) {
            throw new TelegramProviderException(
                'telegram_provider_rejected',
                $response->status(),
            );
        }

        $messageId = $response->json('result.message_id');

        if (! is_int($messageId) && ! is_string($messageId)) {
            throw new TelegramProviderException(
                'telegram_provider_response_invalid',
                $response->status(),
            );
        }

        return new TelegramDeliveryResult((string) $messageId);
    }
}
