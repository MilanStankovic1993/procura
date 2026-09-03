<?php

namespace App\Http\Resources\V1;

use App\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationMembership */
class OrganizationMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->user->name,
            'email' => $this->user->email,
            'role' => $this->role->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'is_current_user' => $request->user()?->getKey() === $this->user_id,
        ];
    }
}
