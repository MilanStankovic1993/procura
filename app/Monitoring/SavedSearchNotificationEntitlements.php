<?php

namespace App\Monitoring;

use App\Enums\Monitoring\NotificationChannel;
use App\Enums\Monitoring\TelegramConnectionStatus;
use App\Enums\Subscriptions\FeatureCode;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Organization;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Monitoring\Telegram\TelegramConfiguration;
use App\Subscriptions\SubscriptionEntitlements;
use App\Support\Validation\ApplicationValidation;

final class SavedSearchNotificationEntitlements
{
    public function __construct(
        private readonly SubscriptionEntitlements $entitlements,
        private readonly TelegramConfiguration $telegramConfiguration,
    ) {}

    /**
     * @param  list<string>  $channels
     * @param  list<string>  $existingChannels
     */
    public function validate(
        Organization $organization,
        array $channels,
        array $existingChannels = [],
        ?User $recipient = null,
    ): void {
        $unsupported = array_diff(
            $channels,
            [
                NotificationChannel::InApp->value,
                NotificationChannel::Email->value,
                NotificationChannel::Telegram->value,
            ],
        );

        if ($unsupported !== []) {
            ApplicationValidation::fail(
                'notification_channels',
                ApplicationValidationCode::UnsupportedNotificationChannel,
            );
        }

        if (! in_array(NotificationChannel::InApp->value, $channels, true)) {
            ApplicationValidation::fail(
                'notification_channels',
                ApplicationValidationCode::InAppNotificationRequired,
            );
        }

        if (
            in_array(NotificationChannel::Email->value, $channels, true)
            && ! in_array(
                NotificationChannel::Email->value,
                $existingChannels,
                true,
            )
            && ! $this->emailEnabled($organization)
        ) {
            ApplicationValidation::fail(
                'notification_channels',
                ApplicationValidationCode::EmailNotificationsPlanDisabled,
            );
        }

        if (
            in_array(
                NotificationChannel::Telegram->value,
                $channels,
                true,
            )
            && ! in_array(
                NotificationChannel::Telegram->value,
                $existingChannels,
                true,
            )
        ) {
            if (! $this->telegramEnabled($organization)) {
                ApplicationValidation::fail(
                    'notification_channels',
                    ApplicationValidationCode::TelegramNotificationsPlanDisabled,
                );
            }

            if (! $this->telegramConfiguration->isConfigured()) {
                ApplicationValidation::fail(
                    'notification_channels',
                    ApplicationValidationCode::TelegramNotConfigured,
                );
            }

            if (
                $recipient === null
                || $this->telegramConnectionFor($recipient) === null
            ) {
                ApplicationValidation::fail(
                    'notification_channels',
                    ApplicationValidationCode::TelegramConnectionRequired,
                );
            }
        }
    }

    public function emailEnabled(Organization $organization): bool
    {
        return $this->entitlements
            ->feature($organization, FeatureCode::EmailNotifications)
            ->is_enabled;
    }

    public function telegramEnabled(Organization $organization): bool
    {
        return $this->entitlements
            ->feature($organization, FeatureCode::TelegramNotifications)
            ->is_enabled;
    }

    public function telegramConnectionFor(
        User $recipient,
    ): ?TelegramConnection {
        return TelegramConnection::query()
            ->where('user_id', $recipient->getKey())
            ->where('status', TelegramConnectionStatus::Connected)
            ->latest('connected_at')
            ->first();
    }
}
