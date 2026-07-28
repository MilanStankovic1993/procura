<?php

namespace App\Actions\Markets;

use App\Enums\Markets\ContinentCode;
use App\Enums\Markets\MeasurementSystem;
use App\Market\CountryReferenceData;
use App\Models\Continent;
use App\Models\Country;
use App\Models\Currency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use NumberFormatter;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Symfony\Component\Intl\Intl;

class SyncMarketReferenceData
{
    public const CACHE_KEY = 'reference.markets.v1';

    /**
     * @return array{continents: int, countries: int, currencies: int, source_version: string}
     */
    public function sync(): array
    {
        $sourceVersion = 'ICU '.Intl::getIcuDataVersion();
        $countryContinents = CountryReferenceData::continentByCountry();
        $countryCodes = Countries::getCountryCodes();
        $unmappedCountries = array_values(array_diff($countryCodes, array_keys($countryContinents)));
        $unknownCountries = array_values(array_diff(array_keys($countryContinents), $countryCodes));

        if ($unmappedCountries !== [] || $unknownCountries !== []) {
            throw new LogicException(sprintf(
                'Country-continent mapping mismatch. Unmapped: %s. Unknown: %s.',
                implode(', ', $unmappedCountries),
                implode(', ', $unknownCountries),
            ));
        }

        $counts = DB::transaction(function () use (
            $countryCodes,
            $countryContinents,
            $sourceVersion,
        ): array {
            $now = now();
            $continents = array_map(
                static fn (ContinentCode $continent): array => [
                    'code' => $continent->value,
                    'name' => $continent->label(),
                    'sort_order' => $continent->sortOrder(),
                    'active' => true,
                    'source_version' => $sourceVersion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ContinentCode::cases(),
            );

            Continent::query()->upsert(
                $continents,
                ['code'],
                ['name', 'sort_order', 'active', 'source_version', 'updated_at'],
            );

            $activeCurrencyCodes = array_fill_keys(
                array_filter(array_map($this->currencyForCountry(...), $countryCodes)),
                true,
            );

            $currencies = array_map(
                static function (string $code) use (
                    $activeCurrencyCodes,
                    $sourceVersion,
                    $now,
                ): array {
                    try {
                        $numericCode = str_pad(
                            (string) Currencies::getNumericCode($code),
                            3,
                            '0',
                            STR_PAD_LEFT,
                        );
                    } catch (MissingResourceException) {
                        $numericCode = null;
                    }

                    return [
                        'code' => $code,
                        'numeric_code' => $numericCode,
                        'name' => Currencies::getName($code, 'en'),
                        'symbol' => Currencies::getSymbol($code, 'en'),
                        'minor_unit' => Currencies::getFractionDigits($code),
                        'cash_minor_unit' => Currencies::getCashFractionDigits($code),
                        'active' => isset($activeCurrencyCodes[$code]) && $code !== 'XXX',
                        'source_version' => $sourceVersion,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                },
                Currencies::getCurrencyCodes(),
            );

            foreach (array_chunk($currencies, 100) as $chunk) {
                Currency::query()->upsert(
                    $chunk,
                    ['code'],
                    [
                        'numeric_code',
                        'name',
                        'symbol',
                        'minor_unit',
                        'cash_minor_unit',
                        'active',
                        'source_version',
                        'updated_at',
                    ],
                );
            }

            $currencyCodes = array_fill_keys(array_column($currencies, 'code'), true);
            $countries = array_map(
                function (string $code) use (
                    $countryContinents,
                    $currencyCodes,
                    $sourceVersion,
                    $now,
                ): array {
                    $currencyCode = $this->currencyForCountry($code);

                    return [
                        'code' => $code,
                        'alpha3_code' => Countries::getAlpha3Code($code),
                        'numeric_code' => Countries::getNumericCode($code),
                        'continent_code' => $countryContinents[$code]->value,
                        'name' => Countries::getName($code, 'en'),
                        'currency_code' => isset($currencyCodes[$currencyCode])
                            && $currencyCode !== 'XXX' ? $currencyCode : null,
                        'measurement_system' => $this->measurementSystemFor($code)->value,
                        'active' => true,
                        'source_version' => $sourceVersion,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                },
                $countryCodes,
            );

            foreach (array_chunk($countries, 100) as $chunk) {
                Country::query()->upsert(
                    $chunk,
                    ['code'],
                    [
                        'alpha3_code',
                        'numeric_code',
                        'continent_code',
                        'name',
                        'currency_code',
                        'measurement_system',
                        'active',
                        'source_version',
                        'updated_at',
                    ],
                );
            }

            return [
                'continents' => count($continents),
                'countries' => count($countries),
                'currencies' => count($currencies),
                'source_version' => $sourceVersion,
            ];
        }, attempts: 3);

        Cache::forget(self::CACHE_KEY);

        return $counts;
    }

    private function currencyForCountry(string $countryCode): ?string
    {
        $formatter = new NumberFormatter("en_{$countryCode}", NumberFormatter::CURRENCY);
        $currency = $formatter->getTextAttribute(NumberFormatter::CURRENCY_CODE);

        return is_string($currency) && strlen($currency) === 3 ? $currency : null;
    }

    private function measurementSystemFor(string $countryCode): MeasurementSystem
    {
        return match ($countryCode) {
            'US', 'LR', 'MM' => MeasurementSystem::UnitedStates,
            'GB' => MeasurementSystem::UnitedKingdom,
            default => MeasurementSystem::Metric,
        };
    }
}
