<?php

namespace App\Enums\Organizations;

enum OrganizationAuditEventType: string
{
    case OrganizationCreated = 'organization.created';
    case OrganizationRenamed = 'organization.renamed';
    case InvitationCreated = 'organization.invitation.created';
    case InvitationRevoked = 'organization.invitation.revoked';
    case InvitationAccepted = 'organization.invitation.accepted';
    case MemberRoleChanged = 'organization.member.role_changed';
    case MemberRemoved = 'organization.member.removed';
    case OwnershipTransferred = 'organization.ownership.transferred';
}
