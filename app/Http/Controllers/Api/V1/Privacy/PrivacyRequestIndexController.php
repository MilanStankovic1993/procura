<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PrivacyRequestResource;
use App\Models\PrivacyRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PrivacyRequestIndexController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $eventLimit = (int) config('privacy.event_history_limit');
        $privacyRequests = PrivacyRequest::query()
            ->where('subject_user_id', $request->user()->getKey())
            ->with([
                'residenceCountry',
                'fulfillment',
                'currentEvent.actor:id,name',
                'events' => fn ($query) => $query
                    ->with('actor:id,name')
                    ->limit($eventLimit),
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit((int) config('privacy.request_history_limit'))
            ->get();

        return PrivacyRequestResource::collection($privacyRequests);
    }
}
