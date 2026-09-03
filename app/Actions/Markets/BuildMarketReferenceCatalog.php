<?php

namespace App\Actions\Markets;

use App\Models\Continent;
use App\Models\Country;
use App\Models\Currency;
use Illuminate\Support\Facades\Cache;

class BuildMarketReferenceCatalog
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return Cache::remember(
            SyncMarketReferenceData::CACHE_KEY,
            now()->addDay(),
            function (): array {
                $countries = Country::query()
                    ->where('active', true)
                    ->orderBy('name')
                    ->get([
                        'code', 'alpha3_code', 'numeric_code', 'continent_code',
                        'name', 'currency_code', 'measurement_system',
                    ])
                    ->groupBy('continent_code');

                $continents = Continent::query()
                    ->where('active', true)
                    ->orderBy('sort_order')
                    ->get(['code', 'name'])
                    ->map(fn (Continent $continent): array => [
                        'code' => $continent->code->value,
                        'name' => $continent->name,
                        'countries' => $countries
                            ->get($continent->code->value, collect())
                            ->map(static fn (Country $country): array => [
                                'code' => $country->getKey(),
                                'alpha3_code' => $country->alpha3_code,
                                'numeric_code' => $country->numeric_code,
                                'name' => $country->name,
                                'currency_code' => $country->currency_code,
                                'measurement_system' => $country->measurement_system->value,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->all();

                $currencies = Currency::query()
                    ->where('active', true)
                    ->orderBy('code')
                    ->get([
                        'code', 'numeric_code', 'name', 'symbol',
                        'minor_unit', 'cash_minor_unit',
                    ])
                    ->map(static fn (Currency $currency): array => [
                        'code' => $currency->getKey(),
                        'numeric_code' => $currency->numeric_code,
                        'name' => $currency->name,
                        'symbol' => $currency->symbol,
                        'minor_unit' => $currency->minor_unit,
                        'cash_minor_unit' => $currency->cash_minor_unit,
                    ])
                    ->all();

                return [
                    'version' => Country::query()->value('source_version'),
                    'continents' => $continents,
                    'currencies' => $currencies,
                ];
            },
        );
    }
}
