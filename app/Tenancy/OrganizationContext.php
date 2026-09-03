<?php

namespace App\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use LogicException;

class OrganizationContext
{
    private ?OrganizationMembership $membership = null;

    public function set(OrganizationMembership $membership): void
    {
        if (! $membership->relationLoaded('organization')) {
            throw new LogicException('The resolved organization membership must load its organization.');
        }

        $this->membership = $membership;
    }

    public function organization(): Organization
    {
        return $this->membership()->organization;
    }

    public function membership(): OrganizationMembership
    {
        return $this->membership
            ?? throw new LogicException('The active organization context has not been resolved.');
    }
}
