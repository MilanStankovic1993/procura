<?php

namespace App\Http\Resources\V1;

use App\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationMembership */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $organization = $this->organization;

        return [
            'id' => $organization->getKey(),
            'name' => $organization->name,
            'type' => $organization->type->value,
            'role' => $this->role->value,
            'capabilities' => array_map(
                static fn ($permission): string => $permission->value,
                $this->role->permissions(),
            ),
            'joined_at' => $this->joined_at?->toIso8601String(),
            'is_active' => $request->user()?->current_organization_id === $organization->getKey(),
            'created_at' => $organization->created_at?->toIso8601String(),
            'updated_at' => $organization->updated_at?->toIso8601String(),
        ];
    }
}
