<?php

namespace App\Http\Resources\V1;

use App\Models\PrivacyRequestEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PrivacyRequestEvent */
final class PrivacyRequestEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'sequence' => $this->sequence,
            'prior_status' => $this->prior_status?->value,
            'next_status' => $this->next_status->value,
            'actor_type' => $this->actor_type->value,
            'actor' => $this->whenLoaded(
                'actor',
                fn (): ?array => $this->actor === null
                    ? null
                    : [
                        'id' => $this->actor->getKey(),
                        'name' => $this->actor->name,
                    ],
            ),
            'reason_code' => $this->reason_code,
            'note' => $this->note,
            'evidence_reference' => $this->evidence_reference,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
