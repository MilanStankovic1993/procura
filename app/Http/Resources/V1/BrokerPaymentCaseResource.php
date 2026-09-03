<?php

namespace App\Http\Resources\V1;

use App\Models\BrokerPaymentCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrokerPaymentCase */
class BrokerPaymentCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'type' => $this->type->value,
            'status' => $this->status->value,
            'requested_amount_minor' => $this->requested_amount_minor,
            'resolved_amount_minor' => $this->resolved_amount_minor,
            'currency_code' => $this->currency_code,
            'resolution_outcome' => $this->resolution_outcome?->value,
            'current_event_id' => $this->current_event_id,
            'event_sequence' => $this->event_sequence,
            'events' => BrokerPaymentCaseEventResource::collection(
                $this->whenLoaded('events'),
            ),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
