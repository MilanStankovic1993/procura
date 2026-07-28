<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Organizations\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrganizationAuditEventResource;
use App\Http\Resources\V1\OrganizationInvitationResource;
use App\Http\Resources\V1\OrganizationMemberResource;
use App\Http\Resources\V1\OrganizationResource;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationManagementController extends Controller
{
    public function __invoke(
        Request $request,
        OrganizationContext $context,
    ): JsonResponse {
        $organization = $context->organization();
        $membership = $context->membership();

        Gate::authorize('viewMembers', $organization);

        $members = $organization->memberships()
            ->with('user')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();

        $invitations = collect();

        if ($membership->role->allows(OrganizationPermission::InviteMembers)) {
            $invitations = $organization->invitations()
                ->with('invitedBy')
                ->whereNotNull('pending_email')
                ->where('expires_at', '>', now())
                ->latest()
                ->get();
        }

        $auditEvents = collect();

        if ($membership->role->allows(OrganizationPermission::ViewAudit)) {
            $auditEvents = $organization->auditEvents()
                ->with(['actor', 'subjectUser'])
                ->latest('created_at')
                ->limit(25)
                ->get();
        }

        $assignableRoles = match ($membership->role) {
            OrganizationRole::Owner => OrganizationRole::invitable(),
            OrganizationRole::Administrator => [
                OrganizationRole::Analyst,
                OrganizationRole::Viewer,
            ],
            default => [],
        };

        return response()->json([
            'data' => [
                'organization' => (new OrganizationResource($membership))->resolve($request),
                'capabilities' => array_map(
                    static fn (OrganizationPermission $permission): string => $permission->value,
                    $membership->role->permissions(),
                ),
                'assignable_roles' => array_map(
                    static fn (OrganizationRole $role): string => $role->value,
                    $assignableRoles,
                ),
                'members' => OrganizationMemberResource::collection($members)->resolve($request),
                'pending_invitations' => OrganizationInvitationResource::collection($invitations)
                    ->resolve($request),
                'audit_events' => OrganizationAuditEventResource::collection($auditEvents)
                    ->resolve($request),
            ],
        ]);
    }
}
