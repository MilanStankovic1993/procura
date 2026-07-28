<?php

namespace App\Http\Resources\V1;

use App\Models\SalePortfolioEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalePortfolioEvent */
class SalePortfolioEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'sale_portfolio_entry_id' => (
                $this->sale_portfolio_entry_id
            ),
            'previous_event_id' => $this->previous_event_id,
            'sequence' => $this->sequence,
            'event_type' => $this->event_type->value,
            'prior_status' => $this->prior_status->value,
            'next_status' => $this->next_status->value,
            'marketplace_name' => $this->marketplace_name,
            'marketplace_key' => $this->marketplace_key,
            'external_listing_id' => $this->external_listing_id,
            'external_listing_url' => $this->external_listing_url,
            'advertised_price_minor' => (
                $this->advertised_price_minor
            ),
            'advertised_currency_code' => (
                $this->advertised_currency_code
            ),
            'reason_code' => $this->reason_code,
            'note' => $this->note,
            'actor' => $this->whenLoaded(
                'actor',
                fn (): array => [
                    'id' => $this->actor->getKey(),
                    'name' => $this->actor->name,
                ],
            ),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
