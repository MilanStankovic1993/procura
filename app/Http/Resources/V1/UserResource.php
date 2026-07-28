<?php

namespace App\Http\Resources\V1;

use App\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    private ?OrganizationMembership $currentOrganizationMembership = null;

    public function withCurrentOrganization(
        OrganizationMembership $membership,
    ): self {
        $this->currentOrganizationMembership = $membership;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'email' => $this->email,
            'preferred_locale' => $this->preferred_locale->value,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'current_organization' => $this->currentOrganizationMembership === null
                ? null
                : OrganizationResource::make($this->currentOrganizationMembership),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
