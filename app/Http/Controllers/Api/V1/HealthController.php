<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Operations\OperationalReadiness;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(
        OperationalReadiness $readiness,
    ): JsonResponse {
        $report = $readiness->inspect();

        return response()
            ->json(
                ['data' => $report->publicPayload()],
                $report->ready ? 200 : 503,
            )
            ->header(
                'Cache-Control',
                'no-store, no-cache, must-revalidate, max-age=0',
            );
    }
}
