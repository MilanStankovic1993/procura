<?php

use App\Actions\Markets\RecordExchangeRate;
use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Pricing\ExchangeRateDirection;
use App\Enums\Pricing\ExchangeRateResolutionStatus;
use App\Models\ExchangeRate;
use App\Pricing\Contracts\ExchangeRateResolver;
use App\Pricing\MinorMoneyConverter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
    config(['price_estimation.max_rate_age_hours' => 72]);
});

test('immutable dated exchange rates resolve identity direct and inverse conversions', function () {
    $calculationAt = CarbonImmutable::parse('2026-07-25T12:00:00Z');
    $attributes = [
        'baseCurrencyCode' => 'USD',
        'quoteCurrencyCode' => 'EUR',
        'rateValue' => '0.800000000000000000',
        'provider' => 'manual-fixture',
        'providerReference' => 'fx-2026-07-25-usd-eur',
        'evidenceHash' => str_repeat('a', 64),
        'effectiveAt' => CarbonImmutable::parse('2026-07-25T10:00:00Z'),
        'publishedAt' => CarbonImmutable::parse('2026-07-25T09:30:00Z'),
        'fetchedAt' => CarbonImmutable::parse('2026-07-25T11:00:00Z'),
        'rawEvidence' => ['fixture' => 'USD/EUR 0.8'],
    ];
    $first = app(RecordExchangeRate::class)->record(...$attributes);
    $duplicate = app(RecordExchangeRate::class)->record(...$attributes);
    $resolver = app(ExchangeRateResolver::class);
    $direct = $resolver->resolve('USD', 'EUR', $calculationAt);
    $inverse = $resolver->resolve('EUR', 'USD', $calculationAt);
    $identity = $resolver->resolve('EUR', 'EUR', $calculationAt);
    $money = app(MinorMoneyConverter::class);

    expect($first['created'])->toBeTrue()
        ->and($duplicate['created'])->toBeFalse()
        ->and($duplicate['rate']->is($first['rate']))->toBeTrue()
        ->and(ExchangeRate::query()->count())->toBe(1)
        ->and($direct->status)->toBe(ExchangeRateResolutionStatus::Resolved)
        ->and($direct->direction)->toBe(ExchangeRateDirection::Direct)
        ->and($direct->rateValue)->toBe('0.800000000000000000')
        ->and($direct->exchangeRateId)->toBe($first['rate']->getKey())
        ->and($inverse->status)->toBe(ExchangeRateResolutionStatus::Resolved)
        ->and($inverse->direction)->toBe(ExchangeRateDirection::Inverse)
        ->and($inverse->rateValue)->toBe('1.250000000000000000')
        ->and($identity->direction)->toBe(ExchangeRateDirection::Identity)
        ->and($identity->exchangeRateId)->toBeNull()
        ->and($money->convert(10000, 2, 2, $direct->rateValue))->toBe(8000)
        ->and($money->convert(10000, 2, 2, $inverse->rateValue))->toBe(12500);

    expect(fn () => $first['rate']->update(['rate' => '0.9']))
        ->toThrow(LogicException::class, 'immutable');
});

test('the resolver rejects stale missing and not-yet-known rate evidence', function () {
    config(['price_estimation.max_rate_age_hours' => 24]);
    $calculationAt = CarbonImmutable::parse('2026-07-25T12:00:00Z');
    app(RecordExchangeRate::class)->record(
        baseCurrencyCode: 'CHF',
        quoteCurrencyCode: 'EUR',
        rateValue: '1.050000000000000000',
        provider: 'manual-fixture',
        providerReference: 'stale-chf-eur',
        evidenceHash: str_repeat('b', 64),
        effectiveAt: CarbonImmutable::parse('2026-07-23T08:00:00Z'),
        publishedAt: CarbonImmutable::parse('2026-07-23T08:00:00Z'),
        fetchedAt: CarbonImmutable::parse('2026-07-23T09:00:00Z'),
        rawEvidence: ['fixture' => 'stale'],
    );
    app(RecordExchangeRate::class)->record(
        baseCurrencyCode: 'GBP',
        quoteCurrencyCode: 'EUR',
        rateValue: '1.150000000000000000',
        provider: 'manual-fixture',
        providerReference: 'future-known-gbp-eur',
        evidenceHash: str_repeat('c', 64),
        effectiveAt: CarbonImmutable::parse('2026-07-25T10:00:00Z'),
        publishedAt: CarbonImmutable::parse('2026-07-25T10:00:00Z'),
        fetchedAt: CarbonImmutable::parse('2026-07-25T13:00:00Z'),
        rawEvidence: ['fixture' => 'not known at calculation time'],
    );
    $resolver = app(ExchangeRateResolver::class);
    $stale = $resolver->resolve('CHF', 'EUR', $calculationAt);
    $missing = $resolver->resolve('JPY', 'EUR', $calculationAt);
    $notYetKnown = $resolver->resolve('GBP', 'EUR', $calculationAt);

    expect($stale->status)->toBe(ExchangeRateResolutionStatus::Stale)
        ->and($stale->reasonCode)->toBe('exchange_rate_stale')
        ->and($missing->status)->toBe(ExchangeRateResolutionStatus::Missing)
        ->and($missing->direction)->toBe(ExchangeRateDirection::Unresolved)
        ->and($notYetKnown->status)->toBe(ExchangeRateResolutionStatus::Missing);
});

test('the operational command records one exact manual rate without external fetching', function () {
    $this->artisan('markets:record-exchange-rate', [
        'base' => 'USD',
        'quote' => 'EUR',
        'rate' => '0.910000000000000000',
        'provider' => 'approved-manual',
        'provider_reference' => 'ops-fixture-1',
        'effective_at' => '2026-07-25T10:00:00Z',
        'evidence_hash' => str_repeat('d', 64),
        '--published-at' => '2026-07-25T10:00:00Z',
        '--fetched-at' => '2026-07-25T11:00:00Z',
        '--note' => 'Verified operational fixture.',
    ])
        ->expectsOutputToContain('Recorded exchange-rate evidence')
        ->assertSuccessful();

    expect(ExchangeRate::query()->count())->toBe(1)
        ->and(ExchangeRate::query()->value('provider'))->toBe('approved-manual');
});
