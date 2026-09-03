<?php

namespace App\Notifications\Monitoring;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NumberFormatter;

class SavedSearchMatchEmailNotification extends Notification
{
    public function __construct(
        private readonly Alert $alert,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $payload = $this->alert->payload;
        $listingTitle = (string) (
            $payload['listing_title']
            ?? __('monitoring.email.saved_search_match.unknown_listing')
        );
        $savedSearchTitle = (string) (
            $payload['saved_search_title']
            ?? __('monitoring.email.saved_search_match.unknown_search')
        );
        $message = (new MailMessage)
            ->subject(__(
                'monitoring.email.saved_search_match.subject',
                ['listing' => $listingTitle],
            ))
            ->greeting(__(
                'monitoring.email.saved_search_match.greeting',
                ['name' => $notifiable->name],
            ))
            ->line(__(
                'monitoring.email.saved_search_match.introduction',
                ['search' => $savedSearchTitle],
            ))
            ->line(__(
                'monitoring.email.saved_search_match.listing',
                ['listing' => $listingTitle],
            ));

        if (
            is_int($payload['asking_price_minor'] ?? null)
            && is_string($payload['currency_code'] ?? null)
        ) {
            $message->line(__(
                'monitoring.email.saved_search_match.asking_price',
                [
                    'price' => $this->money(
                        $payload['asking_price_minor'],
                        $payload['currency_code'],
                        $payload['currency_minor_unit'] ?? null,
                    ),
                ],
            ));
        }

        if (
            is_int($payload['expected_net_profit_minor'] ?? null)
            && is_string($payload['profit_currency_code'] ?? null)
        ) {
            $message->line(__(
                'monitoring.email.saved_search_match.expected_profit',
                [
                    'profit' => $this->money(
                        $payload['expected_net_profit_minor'],
                        $payload['profit_currency_code'],
                        $payload['profit_currency_minor_unit'] ?? null,
                    ),
                ],
            ));
        }

        if (is_int($payload['deal_score_basis_points'] ?? null)) {
            $message->line(__(
                'monitoring.email.saved_search_match.deal_score',
                [
                    'score' => $this->decimal(
                        $payload['deal_score_basis_points'] / 100,
                    ),
                ],
            ));
        }

        if (is_int($payload['risk_score'] ?? null)) {
            $message->line(__(
                'monitoring.email.saved_search_match.risk_score',
                ['score' => $payload['risk_score']],
            ));
        }

        return $message
            ->action(
                __('monitoring.email.saved_search_match.action'),
                rtrim((string) config('app.frontend_url'), '/')
                    .'/app/buy/'
                    .$this->alert->listing_id,
            )
            ->line(__('monitoring.email.saved_search_match.evidence_boundary'));
    }

    private function money(
        int $minor,
        string $currency,
        mixed $minorUnit,
    ): string {
        $minorUnit = is_int($minorUnit) ? $minorUnit : 2;
        $major = $minor / (10 ** $minorUnit);
        $formatter = new NumberFormatter(
            str_replace('-', '_', app()->getLocale()),
            NumberFormatter::CURRENCY,
        );
        $formatted = $formatter->formatCurrency($major, $currency);

        return $formatted === false
            ? sprintf(
                '%s %s',
                $currency,
                number_format($major, $minorUnit, '.', ''),
            )
            : $formatted;
    }

    private function decimal(float $value): string
    {
        $formatter = new NumberFormatter(
            str_replace('-', '_', app()->getLocale()),
            NumberFormatter::DECIMAL,
        );
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);
        $formatted = $formatter->format($value);

        return $formatted === false ? (string) $value : $formatted;
    }
}
