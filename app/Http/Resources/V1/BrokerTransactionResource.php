<?php

namespace App\Http\Resources\V1;

use App\Models\BrokerTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrokerTransaction */
class BrokerTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'status' => $this->status->value,
            'broker_request_offer_id' => $this->broker_request_offer_id,
            'supplier_total_minor' => $this->supplier_total_minor,
            'commission_amount_minor' => $this->commission_amount_minor,
            'payable_total_minor' => $this->payable_total_minor,
            'currency_code' => $this->currency_code,
            'current_event_id' => $this->current_event_id,
            'event_sequence' => $this->event_sequence,
            'events' => BrokerTransactionEventResource::collection(
                $this->whenLoaded('events'),
            ),
            'commission' => new BrokerCommissionResource(
                $this->whenLoaded('commission'),
            ),
            'reports' => BrokerReportResource::collection(
                $this->whenLoaded('reports'),
            ),
            'payment_cases' => BrokerPaymentCaseResource::collection(
                $this->whenLoaded('paymentCases'),
            ),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'payment_confirmed_at' => (
                $this->payment_confirmed_at?->toIso8601String()
            ),
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
