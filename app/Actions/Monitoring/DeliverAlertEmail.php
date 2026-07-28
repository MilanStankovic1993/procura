<?php

namespace App\Actions\Monitoring;

use App\Enums\Localization\SupportedLocale;
use App\Enums\Monitoring\NotificationChannel;
use App\Models\Alert;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Monitoring\NotificationDeliveryLedger;
use App\Monitoring\SavedSearchNotificationEntitlements;
use App\Notifications\Monitoring\SavedSearchMatchEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class DeliverAlertEmail
{
    public function __construct(
        private readonly SavedSearchNotificationEntitlements $entitlements,
        private readonly NotificationDeliveryLedger $ledger,
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
            NotificationChannel::Email,
            $this->provider(),
            $attempt,
            $executionId,
            (int) config(
                'monitoring.email_delivery.attempt_stale_after_seconds',
                300,
            ),
            function (Alert $alert): ?string {
                $recipient = User::query()->find(
                    $alert->recipient_user_id,
                );
                $organization = $alert->organization()->first();
                $membershipExists = OrganizationMembership::query()
                    ->where('organization_id', $alert->organization_id)
                    ->where('user_id', $alert->recipient_user_id)
                    ->exists();

                return match (true) {
                    $recipient === null => 'recipient_missing',
                    ! $membershipExists => 'recipient_membership_removed',
                    ! $recipient->hasVerifiedEmail() => (
                        'recipient_email_unverified'
                    ),
                    $organization === null => 'organization_missing',
                    ! $this->entitlements->emailEnabled($organization) => (
                        'email_entitlement_disabled'
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

        if ($alert === null || $recipient === null) {
            return;
        }

        try {
            $locale = $recipient->preferred_locale;
            $notification = new SavedSearchMatchEmailNotification($alert);

            if ($locale instanceof SupportedLocale) {
                $notification->locale($locale->laravelLocale());
            }

            Notification::sendNow($recipient, $notification, ['mail']);
        } catch (Throwable $exception) {
            $this->ledger->recordFailure(
                $alert->getKey(),
                $attemptLog,
                NotificationChannel::Email,
                $this->provider(),
                $attempt,
                $maximumAttempts,
                $executionId,
                $exception::class,
                Str::limit($exception->getMessage(), 500, ''),
                (array) config(
                    'monitoring.email_delivery.retry_delays_seconds',
                    [60, 300, 900],
                ),
            );

            throw $exception;
        }

        $this->ledger->recordDelivered(
            $alert->getKey(),
            $attemptLog,
            NotificationChannel::Email,
            $this->provider(),
            $attempt,
            $executionId,
        );
    }

    public function markExhausted(
        string $alertId,
        ?Throwable $exception = null,
    ): void {
        $this->ledger->markExhausted(
            $alertId,
            NotificationChannel::Email,
            $this->provider(),
            $exception === null ? null : $exception::class,
            $exception === null
                ? null
                : Str::limit($exception->getMessage(), 500, ''),
        );
    }

    private function provider(): string
    {
        return (string) config(
            'monitoring.email_delivery.provider',
            'laravel-mail',
        );
    }
}
