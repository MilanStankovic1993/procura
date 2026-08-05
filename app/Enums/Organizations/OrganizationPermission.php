<?php

namespace App\Enums\Organizations;

enum OrganizationPermission: string
{
    case UpdateOrganization = 'organization.update';
    case ViewMembers = 'organization.members.view';
    case InviteMembers = 'organization.members.invite';
    case ManageMembers = 'organization.members.manage';
    case TransferOwnership = 'organization.ownership.transfer';
    case ViewAudit = 'organization.audit.view';
    case ManageBilling = 'organization.billing.manage';
    case ViewListings = 'listings.view';
    case ManageListings = 'listings.manage';
    case ViewOwnedProducts = 'owned-products.view';
    case ManageOwnedProducts = 'owned-products.manage';
    case ViewAnalyses = 'analyses.view';
    case ManageAnalyses = 'analyses.manage';
    case ViewSavedSearches = 'saved-searches.view';
    case ManageSavedSearches = 'saved-searches.manage';
    case ViewNotifications = 'notifications.view';
    case ViewBrokerRequests = 'broker-requests.view';
    case ManageBrokerRequests = 'broker-requests.manage';
}
