<?php

namespace App\Http\Resources\V1;

use App\Models\BrokerRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrokerRequest */
class BrokerRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'status' => $this->status->value,
            'title' => $this->title,
            'product_category' => new ProductCategoryResource(
                $this->whenLoaded('productCategory'),
            ),
            'product_category_id' => $this->product_category_id,
            'product_description' => $this->product_description,
            'brand_preference' => $this->brand_preference,
            'model_preference' => $this->model_preference,
            'condition_preference' => $this->condition_preference->value,
            'quantity' => $this->quantity,
            'budget_max_minor' => $this->budget_max_minor,
            'budget_currency_code' => $this->budget_currency_code,
            'target_country_codes' => $this->target_country_codes,
            'needed_by' => $this->needed_by?->toDateString(),
            'notes' => $this->notes,
            'requester' => $this->whenLoaded(
                'requester',
                fn (): array => [
                    'id' => $this->requester->getKey(),
                    'name' => $this->requester->name,
                ],
            ),
            'current_event_id' => $this->current_event_id,
            'event_sequence' => $this->event_sequence,
            'events' => BrokerRequestEventResource::collection(
                $this->whenLoaded('events'),
            ),
            'offers' => BrokerRequestOfferResource::collection(
                $this->whenLoaded('offers'),
            ),
            'transaction' => new BrokerTransactionResource(
                $this->whenLoaded('brokerTransaction'),
            ),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
