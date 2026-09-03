<?php

namespace App\MarketplaceConnectors\Contracts;

use App\MarketplaceConnectors\Data\MarketplaceListingData;
use App\Models\MarketplaceImport;

interface MarketplaceConnector
{
    public function key(): string;

    /** @return list<string> */
    public function capabilities(): array;

    public function supportsAutomatedSearch(): bool;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(
        array $payload,
        MarketplaceImport $import,
    ): MarketplaceListingData;
}
