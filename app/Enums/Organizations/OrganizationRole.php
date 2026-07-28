<?php

namespace App\Enums\Organizations;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Analyst = 'analyst';
    case Viewer = 'viewer';

    /**
     * @return list<OrganizationPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => OrganizationPermission::cases(),
            self::Administrator => [
                OrganizationPermission::UpdateOrganization,
                OrganizationPermission::ViewMembers,
                OrganizationPermission::InviteMembers,
                OrganizationPermission::ManageMembers,
                OrganizationPermission::ViewAudit,
                OrganizationPermission::ViewListings,
                OrganizationPermission::ManageListings,
                OrganizationPermission::ViewOwnedProducts,
                OrganizationPermission::ManageOwnedProducts,
                OrganizationPermission::ViewAnalyses,
                OrganizationPermission::ManageAnalyses,
                OrganizationPermission::ViewSavedSearches,
                OrganizationPermission::ManageSavedSearches,
                OrganizationPermission::ViewNotifications,
            ],
            self::Analyst => [
                OrganizationPermission::ViewMembers,
                OrganizationPermission::ViewListings,
                OrganizationPermission::ManageListings,
                OrganizationPermission::ViewOwnedProducts,
                OrganizationPermission::ManageOwnedProducts,
                OrganizationPermission::ViewAnalyses,
                OrganizationPermission::ManageAnalyses,
                OrganizationPermission::ViewSavedSearches,
                OrganizationPermission::ManageSavedSearches,
                OrganizationPermission::ViewNotifications,
            ],
            self::Viewer => [
                OrganizationPermission::ViewMembers,
                OrganizationPermission::ViewListings,
                OrganizationPermission::ViewOwnedProducts,
                OrganizationPermission::ViewAnalyses,
                OrganizationPermission::ViewSavedSearches,
                OrganizationPermission::ViewNotifications,
            ],
        };
    }

    public function allows(OrganizationPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * @return list<self>
     */
    public static function invitable(): array
    {
        return [
            self::Administrator,
            self::Analyst,
            self::Viewer,
        ];
    }
}
