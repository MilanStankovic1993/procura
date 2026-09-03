<?php

namespace App\Http\Resources\V1;

use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationInvitation */
class OrganizationInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'email' => $this->email,
            'role' => $this->role->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'invited_by' => $this->whenLoaded(
                'invitedBy',
                fn (): ?array => $this->invitedBy === null ? null : [
                    'name' => $this->invitedBy->name,
                    'email' => $this->invitedBy->email,
                ],
            ),
        ];
    }
}
