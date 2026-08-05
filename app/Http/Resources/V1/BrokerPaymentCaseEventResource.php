<?php

namespace App\Http\Resources\V1;

use App\Models\BrokerPaymentCaseEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrokerPaymentCaseEvent */
class BrokerPaymentCaseEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'sequence' => $this->sequence,
            'event_type' => $this->event_type->value,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'resolution_outcome' => $this->resolution_outcome?->value,
            'reason_code' => $this->reason_code,
            'actor' => $this->whenLoaded(
                'actor',
                fn (): ?array => $this->actor === null
                    ? null
                    : [
                        'id' => $this->actor->getKey(),
                        'name' => $this->actor->name,
                    ],
            ),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
