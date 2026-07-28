<?php

namespace App\Http\Resources\V1;

use App\Models\PrivacyRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PrivacyRequest */
final class PrivacyRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'type' => $this->type->value,
            'status' => $this->status->value,
            'current_event_id' => $this->current_event_id,
            'event_sequence' => $this->event_sequence,
            'residence_country_code' => $this->residence_country_code,
            'reason' => $this->reason,
            'blocking_reason_codes' => $this->blocking_reason_codes,
            'workflow_version' => $this->workflow_version,
            'privacy_notice_version' => $this->privacy_notice_version,
            'can_cancel' => $this->status->canBeCancelledBySubject(),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'response_target_at' => (
                $this->response_target_at?->toIso8601String()
            ),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'current_event' => new PrivacyRequestEventResource(
                $this->whenLoaded('currentEvent'),
            ),
            'events' => PrivacyRequestEventResource::collection(
                $this->whenLoaded('events'),
            ),
        ];
    }
}
