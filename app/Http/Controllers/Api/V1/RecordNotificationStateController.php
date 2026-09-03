<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Monitoring\RecordNotificationState;
use App\Enums\Monitoring\NotificationEventType;
use App\Enums\Organizations\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Monitoring\RecordNotificationStateRequest;
use App\Http\Resources\V1\InAppNotificationResource;
use App\Models\Alert;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;

class RecordNotificationStateController extends Controller
{
    public function __invoke(
        RecordNotificationStateRequest $request,
        Alert $alert,
        OrganizationContext $context,
        RecordNotificationState $action,
    ): InAppNotificationResource {
        Gate::allowIf(
            fn (): bool => $context->membership()->role->allows(
                OrganizationPermission::ViewNotifications,
            ),
        );
        $record = Alert::query()
            ->forOrganization($context->organization())
            ->where('recipient_user_id', $request->user()->getKey())
            ->findOrFail($alert->getKey());
        $validated = $request->validated();
        $action->record(
            $record,
            $request->user(),
            NotificationEventType::from($validated['event_type']),
            $validated['expected_current_log_id'],
            $validated['idempotency_key'],
        );

        return new InAppNotificationResource(
            $record->fresh()->load([
                'savedSearch',
                'listing',
                'currentInAppLog',
            ]),
        );
    }
}
