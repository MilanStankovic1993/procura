<?php

namespace App\Http\Resources\V1;

use App\Models\MarketplaceSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MarketplaceSource */
class MarketplaceSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'connector_type' => $this->connector_type->value,
            'capabilities' => $this->capabilities,
            'supported_country_codes' => $this->supported_country_codes,
            'supported_currency_codes' => $this->supported_currency_codes,
            'supported_language_tags' => $this->supported_language_tags,
            'geographic_coverage' => $this->geographic_coverage,
            'cross_border_supported' => $this->cross_border_supported,
            'compliance_status' => $this->compliance_status,
            'available' => $this->key !== MarketplaceSource::AUTHORIZED_CSV_KEY
                || (bool) config('marketplace_connectors.csv_import_enabled'),
            'asking_price_only' => $this->asking_price_only,
            'transaction_price_supported' => $this->transaction_price_supported,
        ];
    }
}
