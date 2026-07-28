<?php

namespace App\Monitoring\Telegram;

use App\Models\Alert;
use Illuminate\Support\Facades\Lang;
use NumberFormatter;

final class SavedSearchMatchTelegramMessage
{
    /**
     * @return array{message: string, action_label: string, action_url: string}
     */
    public function build(Alert $alert, string $locale): array
    {
        $payload = $alert->payload;
        $listing = (string) (
            $payload['listing_title']
            ?? $this->text(
                'monitoring.telegram.saved_search_match.unknown_listing',
                [],
                $locale,
            )
        );
        $search = (string) (
            $payload['saved_search_title']
            ?? $this->text(
                'monitoring.telegram.saved_search_match.unknown_search',
                [],
                $locale,
            )
        );
        $lines = [
            $this->text(
                'monitoring.telegram.saved_search_match.title',
                [],
                $locale,
            ),
            '',
            $this->text(
                'monitoring.telegram.saved_search_match.search',
                ['search' => $search],
                $locale,
            ),
            $this->text(
                'monitoring.telegram.saved_search_match.listing',
                ['listing' => $listing],
                $locale,
            ),
        ];

        if (
            is_int($payload['asking_price_minor'] ?? null)
            && is_string($payload['currency_code'] ?? null)
        ) {
            $lines[] = $this->text(
                'monitoring.telegram.saved_search_match.asking_price',
                [
                    'price' => $this->money(
                        $payload['asking_price_minor'],
                        $payload['currency_code'],
                        $payload['currency_minor_unit'] ?? null,
                        $locale,
                    ),
                ],
                $locale,
            );
        }

        if (
            is_int($payload['expected_net_profit_minor'] ?? null)
            && is_string($payload['profit_currency_code'] ?? null)
        ) {
            $lines[] = $this->text(
                'monitoring.telegram.saved_search_match.expected_profit',
                [
                    'profit' => $this->money(
                        $payload['expected_net_profit_minor'],
                        $payload['profit_currency_code'],
                        $payload['profit_currency_minor_unit'] ?? null,
                        $locale,
                    ),
                ],
                $locale,
            );
        }

        if (is_int($payload['deal_score_basis_points'] ?? null)) {
            $lines[] = $this->text(
                'monitoring.telegram.saved_search_match.deal_score',
                [
                    'score' => $this->decimal(
                        $payload['deal_score_basis_points'] / 100,
                        $locale,
                    ),
                ],
                $locale,
            );
        }

        if (is_int($payload['risk_score'] ?? null)) {
            $lines[] = $this->text(
                'monitoring.telegram.saved_search_match.risk_score',
                ['score' => $payload['risk_score']],
                $locale,
            );
        }

        $lines[] = '';
        $lines[] = $this->text(
            'monitoring.telegram.saved_search_match.evidence_boundary',
            [],
            $locale,
        );

        return [
            'message' => implode("\n", $lines),
            'action_label' => $this->text(
                'monitoring.telegram.saved_search_match.action',
                [],
                $locale,
            ),
            'action_url' => rtrim(
                (string) config('app.frontend_url'),
                '/',
            ).'/app/buy/'.$alert->listing_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function text(
        string $key,
        array $replace,
        string $locale,
    ): string {
        return (string) Lang::get($key, $replace, $locale);
    }

    private function money(
        int $minor,
        string $currency,
        mixed $minorUnit,
        string $locale,
    ): string {
        $minorUnit = is_int($minorUnit) ? $minorUnit : 2;
        $major = $minor / (10 ** $minorUnit);
        $formatter = new NumberFormatter(
            str_replace('-', '_', $locale),
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

    private function decimal(float $value, string $locale): string
    {
        $formatter = new NumberFormatter(
            str_replace('-', '_', $locale),
            NumberFormatter::DECIMAL,
        );
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);
        $formatted = $formatter->format($value);

        return $formatted === false ? (string) $value : $formatted;
    }
}
