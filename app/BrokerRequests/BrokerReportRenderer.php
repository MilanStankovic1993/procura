<?php

namespace App\BrokerRequests;

use App\BrokerRequests\Data\RenderedBrokerReport;
use App\Enums\Localization\SupportedLocale;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Lang;
use IntlDateFormatter;
use NumberFormatter;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;
use Throwable;

final class BrokerReportRenderer
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function render(array $snapshot, SupportedLocale $locale): RenderedBrokerReport
    {
        $html = view('pdf.broker-transaction-report', [
            'report' => $this->viewData($snapshot, $locale),
        ])->render();
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text(
            508,
            812,
            '{PAGE_NUM} / {PAGE_COUNT}',
            $font,
            8,
            [0.36, 0.42, 0.49],
        );
        $bytes = $dompdf->output();

        return new RenderedBrokerReport(
            bytes: $bytes,
            pageCount: $canvas->get_page_count(),
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function viewData(
        array $snapshot,
        SupportedLocale $locale,
    ): array {
        $currency = (string) $snapshot['offer']['currency_code'];
        $label = fn (string $key): string => (string) Lang::get(
            "broker_report.{$key}",
            [],
            $locale->laravelLocale(),
        );
        $status = fn (?string $value): string => $value === null
            ? $label('not_available')
            : (string) Lang::get(
                "broker_report.status.{$value}",
                [],
                $locale->laravelLocale(),
            );

        return [
            'labels' => [
                'title' => $label('title'),
                'subtitle' => $label('subtitle'),
                'report_details' => $label('report_details'),
                'report_version' => $label('report_version'),
                'locale' => $label('locale'),
                'generated_at' => $label('generated_at'),
                'request' => $label('request'),
                'request_id' => $label('request_id'),
                'description' => $label('description'),
                'brand_model' => $label('brand_model'),
                'condition' => $label('condition'),
                'quantity' => $label('quantity'),
                'budget' => $label('budget'),
                'target_countries' => $label('target_countries'),
                'needed_by' => $label('needed_by'),
                'accepted_offer' => $label('accepted_offer'),
                'supplier' => $label('supplier'),
                'item' => $label('item'),
                'unit_price' => $label('unit_price'),
                'subtotal' => $label('subtotal'),
                'shipping' => $label('shipping'),
                'tax_and_duty' => $label('tax_and_duty'),
                'other_costs' => $label('other_costs'),
                'supplier_total' => $label('supplier_total'),
                'commission' => $label('commission'),
                'payable_total' => $label('payable_total'),
                'origin' => $label('origin'),
                'delivery' => $label('delivery'),
                'warranty' => $label('warranty'),
                'return_policy' => $label('return_policy'),
                'transaction' => $label('transaction'),
                'transaction_id' => $label('transaction_id'),
                'status' => $label('status_label'),
                'timeline' => $label('timeline'),
                'event' => $label('event'),
                'occurred_at' => $label('occurred_at'),
                'commission_details' => $label('commission_details'),
                'rule' => $label('rule'),
                'rate' => $label('rate'),
                'base' => $label('base'),
                'amount' => $label('amount'),
                'recorded_at' => $label('recorded_at'),
                'earned_at' => $label('earned_at'),
                'settled_at' => $label('settled_at'),
                'integrity_notice' => $label('integrity_notice'),
            ],
            'meta' => [
                'version' => $snapshot['version'],
                'locale' => $locale->nativeLabel(),
                'generated_at' => $this->dateTime(
                    $snapshot['generated_at'],
                    $locale,
                ),
            ],
            'request' => [
                ...$snapshot['request'],
                'condition' => $status(
                    $snapshot['request']['condition_preference'],
                ),
                'brand_model' => $this->joinPresent([
                    $snapshot['request']['brand_preference'],
                    $snapshot['request']['model_preference'],
                ], $label('not_available')),
                'budget' => $snapshot['request']['budget_max_minor'] === null
                    ? $label('not_available')
                    : $this->money(
                        $snapshot['request']['budget_max_minor'],
                        $snapshot['request']['budget_currency_code'],
                        $locale,
                    ),
                'countries' => implode(', ', array_map(
                    fn (string $code): string => $this->country(
                        $code,
                        $locale,
                    ),
                    $snapshot['request']['target_country_codes'],
                )),
                'needed_by_display' => $this->date(
                    $snapshot['request']['needed_by'],
                    $locale,
                    $label('not_available'),
                ),
            ],
            'offer' => [
                ...$snapshot['offer'],
                'condition_display' => $status($snapshot['offer']['condition']),
                'unit_price' => $this->money(
                    $snapshot['offer']['unit_price_minor'],
                    $currency,
                    $locale,
                ),
                'subtotal' => $this->money(
                    $snapshot['offer']['item_subtotal_minor'],
                    $currency,
                    $locale,
                ),
                'shipping' => $this->money(
                    $snapshot['offer']['shipping_cost_minor'],
                    $currency,
                    $locale,
                ),
                'tax' => $this->money(
                    $snapshot['offer']['tax_duty_cost_minor'],
                    $currency,
                    $locale,
                ),
                'other' => $this->money(
                    $snapshot['offer']['other_cost_minor'],
                    $currency,
                    $locale,
                ),
                'total' => $this->money(
                    $snapshot['offer']['total_minor'],
                    $currency,
                    $locale,
                ),
                'commission' => $this->money(
                    $snapshot['offer']['commission_amount_minor'],
                    $currency,
                    $locale,
                ),
                'payable' => $this->money(
                    $snapshot['offer']['payable_total_minor'],
                    $currency,
                    $locale,
                ),
                'origin' => $this->country(
                    $snapshot['offer']['origin_country_code'],
                    $locale,
                ),
                'delivery' => $this->date(
                    $snapshot['offer']['estimated_delivery_date'],
                    $locale,
                    $label('not_available'),
                ),
                'warranty' => $snapshot['offer']['warranty_months'] === null
                    ? $label('not_available')
                    : $snapshot['offer']['warranty_months'].' '
                        .$label('months'),
                'return_policy' => $snapshot['offer']['return_policy_summary']
                    ?? $label('not_available'),
            ],
            'transaction' => [
                ...$snapshot['transaction'],
                'status_display' => $status($snapshot['transaction']['status']),
                'timeline' => array_map(
                    fn (array $event): array => [
                        ...$event,
                        'event_display' => $status($event['event_type']),
                        'status_display' => $status($event['to_status']),
                        'occurred_display' => $this->dateTime(
                            $event['occurred_at'],
                            $locale,
                        ),
                    ],
                    $snapshot['transaction']['timeline'],
                ),
            ],
            'commission' => [
                ...$snapshot['commission'],
                'status_display' => $status($snapshot['commission']['status']),
                'rate_display' => $this->basisPoints(
                    $snapshot['commission']['rate_basis_points'],
                    $locale,
                ),
                'base_display' => $this->money(
                    $snapshot['commission']['base_minor'],
                    $currency,
                    $locale,
                ),
                'amount_display' => $this->money(
                    $snapshot['commission']['amount_minor'],
                    $currency,
                    $locale,
                ),
                'recorded_display' => $this->dateTime(
                    $snapshot['commission']['recorded_at'],
                    $locale,
                ),
                'earned_display' => $this->dateTime(
                    $snapshot['commission']['earned_at'],
                    $locale,
                ),
                'settled_display' => $this->dateTime(
                    $snapshot['commission']['settled_at'],
                    $locale,
                    $label('not_available'),
                ),
            ],
        ];
    }

    private function money(
        int $minor,
        string $currency,
        SupportedLocale $locale,
    ): string {
        try {
            $digits = Currencies::getFractionDigits($currency);
        } catch (Throwable) {
            $digits = 2;
        }

        $negative = $minor < 0;
        $absolute = abs($minor);
        $divisor = 10 ** $digits;
        $whole = intdiv($absolute, $divisor);
        $fraction = $digits === 0
            ? ''
            : str_pad((string) ($absolute % $divisor), $digits, '0', STR_PAD_LEFT);
        $formatter = new NumberFormatter(
            $locale->value,
            NumberFormatter::DECIMAL,
        );
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        $formattedWhole = $formatter->format($whole, NumberFormatter::TYPE_INT64);
        $separator = $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        return ($negative ? '-' : '')
            .$formattedWhole
            .($digits === 0 ? '' : $separator.$fraction)
            .' '
            .$currency;
    }

    private function basisPoints(
        int $basisPoints,
        SupportedLocale $locale,
    ): string {
        $whole = intdiv($basisPoints, 100);
        $fraction = str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
        $formatter = new NumberFormatter($locale->value, NumberFormatter::DECIMAL);

        return $whole
            .$formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL)
            .$fraction
            .'%';
    }

    private function date(
        ?string $value,
        SupportedLocale $locale,
        string $fallback,
    ): string {
        if ($value === null) {
            return $fallback;
        }

        return $this->dateFormatter(
            $locale,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::NONE,
        )->format(CarbonImmutable::parse($value)) ?: $fallback;
    }

    private function dateTime(
        ?string $value,
        SupportedLocale $locale,
        string $fallback = '',
    ): string {
        if ($value === null) {
            return $fallback;
        }

        return $this->dateFormatter(
            $locale,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::SHORT,
        )->format(CarbonImmutable::parse($value)) ?: $fallback;
    }

    private function dateFormatter(
        SupportedLocale $locale,
        int $dateType,
        int $timeType,
    ): IntlDateFormatter {
        return new IntlDateFormatter(
            $locale->value,
            $dateType,
            $timeType,
            config('app.timezone', 'UTC'),
        );
    }

    private function country(string $code, SupportedLocale $locale): string
    {
        try {
            return Countries::getName($code, $locale->laravelLocale());
        } catch (Throwable) {
            return $code;
        }
    }

    /**
     * @param  list<?string>  $values
     */
    private function joinPresent(array $values, string $fallback): string
    {
        $present = array_values(array_filter(
            $values,
            static fn (?string $value): bool => $value !== null && $value !== '',
        ));

        return $present === [] ? $fallback : implode(' ', $present);
    }
}
