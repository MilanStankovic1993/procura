<?php

namespace App\Http\Resources\V1;

use App\Models\OrganizationAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationAuditEvent */
class OrganizationAuditEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'event' => $this->event->value,
            'actor' => $this->whenLoaded(
                'actor',
                fn (): ?array => $this->actor === null ? null : [
                    'name' => $this->actor->name,
                    'email' => $this->actor->email,
                ],
            ),
            'subject' => $this->whenLoaded(
                'subjectUser',
                fn (): ?array => $this->subjectUser === null ? null : [
                    'name' => $this->subjectUser->name,
                    'email' => $this->subjectUser->email,
                ],
            ),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
