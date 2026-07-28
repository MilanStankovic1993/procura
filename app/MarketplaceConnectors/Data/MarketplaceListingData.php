<?php

namespace App\MarketplaceConnectors\Data;

final readonly class MarketplaceListingData
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(public array $attributes) {}
}
