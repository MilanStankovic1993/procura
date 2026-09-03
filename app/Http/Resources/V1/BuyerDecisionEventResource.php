<?php

namespace App\Http\Resources\V1;

use App\Models\BuyerDecisionEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BuyerDecisionEvent */
class BuyerDecisionEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'sequence' => $this->sequence,
            'analysis_id' => $this->analysis_id,
            'deal_score_id' => $this->deal_score_id,
            'previous_event_id' => $this->previous_event_id,
            'prior_state' => $this->prior_state?->value,
            'next_state' => $this->next_state->value,
            'reason_code' => $this->reason_code,
            'note' => $this->note,
            'actor' => $this->whenLoaded(
                'actor',
                fn (): array => [
                    'id' => $this->actor->getKey(),
                    'name' => $this->actor->name,
                ],
            ),
            'deal_score' => $this->whenLoaded(
                'dealScore',
                fn (): array => [
                    'id' => $this->dealScore->getKey(),
                    'run_number' => $this->dealScore->run_number,
                    'score' => $this->dealScore->score,
                    'recommendation' => (
                        $this->dealScore->recommendation->value
                    ),
                ],
            ),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
