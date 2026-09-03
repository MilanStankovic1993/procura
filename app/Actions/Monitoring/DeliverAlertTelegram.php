<?php

namespace App\Actions\Monitoring;

use App\Enums\Localization\SupportedLocale;
use App\Enums\Monitoring\NotificationChannel;
use App\Enums\Monitoring\TelegramConnectionStatus;
use App\Models\Alert;
use App\Models\NotificationLog;
use App\Models\OrganizationMembership;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Monitoring\NotificationDeliveryLedger;
use App\Monitoring\SavedSearchNotificationEntitlements;
use App\Monitoring\Telegram\Contracts\TelegramProvider;
use App\Monitoring\Telegram\Exceptions\TelegramProviderException;
use App\Monitoring\Telegram\SavedSearchMatchTelegramMessage;
use App\Monitoring\Telegram\TelegramConfiguration;
use Illuminate\Support\Str;
use Throwable;

final class DeliverAlertTelegram
{
    public function __construct(
        private readonly SavedSearchNotificationEntitlements $entitlements,
        private readonly NotificationDeliveryLedger $ledger,
        private readonly TelegramProvider $provider,
        private readonly TelegramConfiguration $configuration,
        private readonly SavedSearchMatchTelegramMessage $message,
    ) {}

    /**
     * @throws Throwable
     */
    public function deliver(
        string $alertId,
        int $attempt,
        int $maximumAttempts,
    ): void {
        $executionId = (string) Str::uuid();
        $attemptLog = $this->ledger->beginAttempt(
            $alertId,
            NotificationChannel::Telegram,
            $this->configuration->provider(),
            $attempt,
            $executionId,
            (int) config(
                'monitoring.telegram.delivery.attempt_stale_after_seconds',
                300,
            ),
            function (
                Alert $alert,
                NotificationLog $current,
            ): ?string {
                $recipient = User::query()->find(
                    $alert->recipient_user_id,
                );
                $organization = $alert->organization()->first();
                $membershipExists = OrganizationMembership::query()
                    ->where('organization_id', $alert->organization_id)
                    ->where('user_id', $alert->recipient_user_id)
                    ->exists();
                $connectionId = (
                    $current->payload['delivery']['connection_id']
                    ?? null
                );
                $connection = is_string($connectionId)
                    ? TelegramConnection::query()->find($connectionId)
                    : null;

                return match (true) {
                    $recipient === null => 'recipient_missing',
                    ! $membershipExists => 'recipient_membership_removed',
                    $organization === null => 'organization_missing',
                    ! $this->entitlements->telegramEnabled(
                        $organization,
                    ) => 'telegram_entitlement_disabled',
                    ! $this->configuration->isConfigured() => (
                        'telegram_provider_not_configured'
                    ),
                    $connection === null => (
                        'telegram_connection_missing'
                    ),
                    $connection->user_id !== $alert->recipient_user_id => (
                        'telegram_connection_owner_mismatch'
                    ),
                    $connection->status
                        !== TelegramConnectionStatus::Connected => (
                            'telegram_connection_revoked'
                        ),
                    default => null,
                };
            },
        );

        if ($attemptLog === null) {
            return;
        }

        $alert = Alert::query()->find($alertId);
        $recipient = $alert?->recipient()->first();
        $connectionId = (
            $attemptLog->payload['delivery']['connection_id']
            ?? null
        );
        $connection = is_string($connectionId)
            ? TelegramConnection::query()->find($connectionId)
            : null;

        if (
            $alert === null
            || $recipient === null
            || $connection === null
        ) {
            return;
        }

        $preferredLocale = $recipient->preferred_locale;
        $locale = $preferredLocale instanceof SupportedLocale
            ? $preferredLocale->laravelLocale()
            : SupportedLocale::English->laravelLocale();
        $content = $this->message->build($alert, $locale);

        try {
            $result = $this->provider->sendMessage(
                $connection,
                $content['message'],
                $content['action_label'],
                $content['action_url'],
            );
        } catch (Throwable $exception) {
            $this->ledger->recordFailure(
                $alert->getKey(),
                $attemptLog,
                NotificationChannel::Telegram,
                $this->configuration->provider(),
                $attempt,
                $maximumAttempts,
                $executionId,
                $exception::class,
                $this->safeSummary($exception),
                (array) config(
                    'monitoring.telegram.delivery.retry_delays_seconds',
                    [30, 120, 600],
                ),
            );

            throw $exception;
        }

        $this->ledger->recordDelivered(
            $alert->getKey(),
            $attemptLog,
            NotificationChannel::Telegram,
            $this->configuration->provider(),
            $attempt,
            $executionId,
            [
                'provider_message_id' => $result->providerMessageId,
            ],
        );
    }

    public function markExhausted(
        string $alertId,
        ?Throwable $exception = null,
    ): void {
        $this->ledger->markExhausted(
            $alertId,
            NotificationChannel::Telegram,
            $this->configuration->provider(),
            $exception === null ? null : $exception::class,
            $exception === null ? null : $this->safeSummary($exception),
        );
    }

    private function safeSummary(Throwable $exception): string
    {
        return $exception instanceof TelegramProviderException
            ? $exception->reasonCode
            : 'unexpected_telegram_provider_failure';
    }
}
