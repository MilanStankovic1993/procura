<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Monitoring\NotificationChannel;
use App\Enums\Monitoring\NotificationEventType;
use App\Enums\Organizations\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Monitoring\IndexNotificationRequest;
use App\Http\Resources\V1\InAppNotificationResource;
use App\Models\Alert;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class NotificationIndexController extends Controller
{
    public function __invoke(
        IndexNotificationRequest $request,
        OrganizationContext $context,
    ): AnonymousResourceCollection {
        Gate::allowIf(
            fn (): bool => $context->membership()->role->allows(
                OrganizationPermission::ViewNotifications,
            ),
        );
        $validated = $request->validated();
        $state = $validated['state'] ?? 'all';
        $base = Alert::query()
            ->forOrganization($context->organization())
            ->where('recipient_user_id', $request->user()->getKey())
            ->whereHas(
                'logs',
                fn (Builder $query) => $this->currentLog($query)
                    ->where(
                        'event_type',
                        '!=',
                        NotificationEventType::Archived,
                    ),
            );
        $query = (clone $base)
            ->with([
                'savedSearch',
                'listing',
                'currentInAppLog',
                'currentEmailLog',
                'currentTelegramLog',
            ])
            ->when(
                $state === 'read',
                fn ($query) => $query->whereHas(
                    'logs',
                    fn (Builder $logs) => $this->currentLog($logs)
                        ->where('event_type', NotificationEventType::Read),
                ),
            )
            ->when(
                $state === 'unread',
                fn ($query) => $query->whereHas(
                    'logs',
                    fn (Builder $logs) => $this->currentLog($logs)
                        ->whereIn('event_type', [
                            NotificationEventType::Delivered,
                            NotificationEventType::Unread,
                        ]),
                ),
            )
            ->orderByDesc('triggered_at')
            ->orderByDesc('id');
        $unreadCount = (clone $base)
            ->whereHas(
                'logs',
                fn (Builder $logs) => $this->currentLog($logs)
                    ->whereIn('event_type', [
                        NotificationEventType::Delivered,
                        NotificationEventType::Unread,
                    ]),
            )
            ->count();

        return InAppNotificationResource::collection(
            $query->cursorPaginate((int) ($validated['per_page'] ?? 20)),
        )->additional(['meta' => ['unread_count' => $unreadCount]]);
    }

    private function currentLog(Builder $query): Builder
    {
        return $query
            ->where('channel', NotificationChannel::InApp)
            ->where(
                'sequence',
                '=',
                function ($subquery): void {
                    $subquery
                        ->selectRaw('MAX(latest_logs.sequence)')
                        ->from('notification_logs as latest_logs')
                        ->whereColumn(
                            'latest_logs.alert_id',
                            'notification_logs.alert_id',
                        )
                        ->where(
                            'latest_logs.channel',
                            NotificationChannel::InApp->value,
                        );
                },
            );
    }
}
