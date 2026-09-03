<?php

namespace App\MarketplaceConnectors;

use App\MarketplaceConnectors\Connectors\AuthorizedCsvConnector;
use App\MarketplaceConnectors\Contracts\MarketplaceConnector;
use App\Models\MarketplaceSource;
use DomainException;

final class ConnectorRegistry
{
    public function __construct(
        private readonly AuthorizedCsvConnector $authorizedCsv,
    ) {}

    public function forSource(MarketplaceSource $source): MarketplaceConnector
    {
        $connector = match ($source->key) {
            MarketplaceSource::AUTHORIZED_CSV_KEY => $this->authorizedCsv,
            default => null,
        };

        if (
            $connector === null
            || ! $source->active
            || $source->compliance_status !== 'approved'
            || (
                $source->key === MarketplaceSource::AUTHORIZED_CSV_KEY
                && ! config('marketplace_connectors.csv_import_enabled')
            )
        ) {
            throw new DomainException('The marketplace connector is not approved for import.');
        }

        return $connector;
    }
}
