<?php

namespace App\Http\Resources\V1;

use App\Enums\Outcomes\ActualSaleOutcomeType;
use App\Enums\Sell\SalePortfolioEventType;
use App\Enums\Sell\SalePortfolioStatus;
use App\Models\SalePortfolioEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalePortfolioEntry */
class SalePortfolioEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->currentEvent?->next_status
            ?? SalePortfolioStatus::Draft;
        $sold = $this->relationLoaded('currentActualSale')
            && $this->currentActualSale?->outcome_type
                === ActualSaleOutcomeType::Sold;

        return [
            'id' => $this->getKey(),
            'owned_product_id' => $this->owned_product_id,
            'sell_listing_draft_id' => $this->sell_listing_draft_id,
            'sequence' => $this->sequence,
            'source_evidence_current' => (
                (bool) $this->getAttribute('source_evidence_current')
            ),
            'current_status' => $status->value,
            'current_event_id' => $this->currentEvent?->getKey(),
            'current_actual_sale_id' => $this->when(
                $this->relationLoaded('currentActualSale'),
                $this->currentActualSale?->getKey(),
            ),
            'allowed_events' => $sold
                ? []
                : collect(
                    SalePortfolioEventType::allowedFrom($status),
                )->map->value->all(),
            'listing_draft_input_hash' => (
                $this->listing_draft_input_hash
            ),
            'target_country_code' => $this->target_country_code,
            'target_currency_code' => $this->target_currency_code,
            'listing_language' => $this->listing_language,
            'price_strategy' => $this->price_strategy->value,
            'initial_asking_price_minor' => (
                $this->initial_asking_price_minor
            ),
            'listing_title' => $this->whenLoaded(
                'listingDraft',
                fn (): ?string => $this->listingDraft?->title,
            ),
            'created_by' => $this->whenLoaded(
                'createdBy',
                fn (): array => [
                    'id' => $this->createdBy->getKey(),
                    'name' => $this->createdBy->name,
                ],
            ),
            'current_event' => $this->whenLoaded(
                'currentEvent',
                fn (): ?array => $this->currentEvent === null
                    ? null
                    : (new SalePortfolioEventResource(
                        $this->currentEvent,
                    ))->resolve($request),
            ),
            'events' => $this->whenLoaded(
                'events',
                fn (): array => SalePortfolioEventResource::collection(
                    $this->events,
                )->resolve($request),
            ),
            'entered_at' => $this->entered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
