<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Actions\Privacy\CreatePrivacyRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Privacy\StorePrivacyRequestRequest;
use App\Http\Resources\V1\PrivacyRequestResource;
use Illuminate\Http\JsonResponse;

final class StorePrivacyRequestController extends Controller
{
    public function __invoke(
        StorePrivacyRequestRequest $request,
        CreatePrivacyRequest $action,
    ): JsonResponse {
        $result = $action->create(
            subject: $request->user(),
            attributes: $request->validated(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
        $result['privacy_request']->load([
            'residenceCountry',
            'currentEvent.actor:id,name',
            'events.actor:id,name',
        ]);

        return (new PrivacyRequestResource($result['privacy_request']))
            ->additional([
                'meta' => ['created' => $result['created']],
            ])
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }
}
