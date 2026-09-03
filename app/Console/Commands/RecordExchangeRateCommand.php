<?php

namespace App\Console\Commands;

use App\Actions\Markets\RecordExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RecordExchangeRateCommand extends Command
{
    protected $signature = 'markets:record-exchange-rate
        {base : Source ISO 4217 currency code}
        {quote : Target ISO 4217 currency code}
        {rate : Quote-major units per one base-major unit}
        {provider : Approved provider identifier}
        {provider_reference : Provider publication or observation reference}
        {effective_at : Effective timestamp}
        {evidence_hash : SHA-256 hash of the preserved source evidence}
        {--published-at= : Provider publication timestamp}
        {--fetched-at= : Retrieval timestamp; defaults to now}
        {--note= : Optional operational evidence note}';

    protected $description = 'Append one immutable, manually verified exchange-rate observation';

    public function handle(RecordExchangeRate $rates): int
    {
        try {
            $effectiveAt = CarbonImmutable::parse(
                (string) $this->argument('effective_at'),
            );
            $publishedAt = $this->option('published-at') === null
                ? null
                : CarbonImmutable::parse((string) $this->option('published-at'));
            $fetchedAt = $this->option('fetched-at') === null
                ? CarbonImmutable::now()
                : CarbonImmutable::parse((string) $this->option('fetched-at'));
            $result = $rates->record(
                baseCurrencyCode: (string) $this->argument('base'),
                quoteCurrencyCode: (string) $this->argument('quote'),
                rateValue: (string) $this->argument('rate'),
                provider: (string) $this->argument('provider'),
                providerReference: (string) $this->argument('provider_reference'),
                evidenceHash: (string) $this->argument('evidence_hash'),
                effectiveAt: $effectiveAt,
                publishedAt: $publishedAt,
                fetchedAt: $fetchedAt,
                rawEvidence: [
                    'entry_method' => 'operator_cli',
                    'note' => $this->option('note'),
                ],
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s exchange-rate evidence %s (%s/%s).',
            $result['created'] ? 'Recorded' : 'Reused',
            $result['rate']->getKey(),
            $result['rate']->base_currency_code,
            $result['rate']->quote_currency_code,
        ));

        return self::SUCCESS;
    }
}
