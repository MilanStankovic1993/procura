<?php

use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Markets\MeasurementSystem;
use App\Models\Continent;
use App\Models\Country;
use App\Models\Currency;
use Illuminate\Support\Facades\DB;

test('market reference synchronization is complete and idempotent', function () {
    $sync = app(SyncMarketReferenceData::class);

    $first = $sync->sync();
    $second = $sync->sync();

    expect($first)->toBe($second)
        ->and(Continent::query()->count())->toBe(7)
        ->and(Country::query()->count())->toBe(249)
        ->and(Currency::query()->count())->toBeGreaterThan(150)
        ->and(Country::query()->whereNull('continent_code')->exists())->toBeFalse()
        ->and(Country::query()->where('code', 'US')->value('measurement_system'))
        ->toBe(MeasurementSystem::UnitedStates)
        ->and(Country::query()->where('code', 'GB')->value('measurement_system'))
        ->toBe(MeasurementSystem::UnitedKingdom)
        ->and(Country::query()->where('code', 'DE')->value('currency_code'))->toBe('EUR');
});

test('the public market reference endpoint is cacheable versioned and query bounded', function () {
    app(SyncMarketReferenceData::class)->sync();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->getJson(route('api.v1.reference.markets'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=86400, public, stale-while-revalidate=604800')
        ->assertJsonPath('data.continents.0.code', 'AF')
        ->assertJsonCount(7, 'data.continents')
        ->assertJsonPath(
            'data.version',
            fn (string $version): bool => str_starts_with($version, 'ICU '),
        );

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);

    $etag = $response->headers->get('ETag');

    $this->withHeader('If-None-Match', $etag)
        ->get(route('api.v1.reference.markets'))
        ->assertNotModified()
        ->assertHeader('ETag', $etag);
});

test('inactive reference records are excluded without deleting historical keys', function () {
    app(SyncMarketReferenceData::class)->sync();
    Country::query()->where('code', 'DE')->update(['active' => false]);
    cache()->forget(SyncMarketReferenceData::CACHE_KEY);

    $countries = collect(
        $this->getJson(route('api.v1.reference.markets'))
            ->assertOk()
            ->json('data.continents'),
    )->flatMap(fn (array $continent): array => $continent['countries']);

    expect($countries->pluck('code'))->not->toContain('DE')
        ->and(Country::query()->find('DE'))->not->toBeNull();
});
