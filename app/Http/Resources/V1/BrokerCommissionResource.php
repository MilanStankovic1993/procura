<?php

namespace App\Http\Resources\V1;

use App\Models\BrokerCommission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrokerCommission */
class BrokerCommissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'status' => $this->status->value,
            'rule_version' => $this->rule_version,
            'rate_basis_points' => $this->rate_basis_points,
            'base_minor' => $this->base_minor,
            'amount_minor' => $this->amount_minor,
            'currency_code' => $this->currency_code,
            'current_event_id' => $this->current_event_id,
            'event_sequence' => $this->event_sequence,
            'events' => BrokerCommissionEventResource::collection(
                $this->whenLoaded('events'),
            ),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'earned_at' => $this->earned_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'waived_at' => $this->waived_at?->toIso8601String(),
        ];
    }
}
