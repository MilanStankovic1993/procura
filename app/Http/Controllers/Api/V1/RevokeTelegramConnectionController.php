<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Monitoring\RevokeTelegramConnection;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RevokeTelegramConnectionController extends Controller
{
    public function __invoke(
        Request $request,
        RevokeTelegramConnection $revoke,
    ): JsonResponse {
        $revoke->revoke($request->user());

        return response()->json([
            'data' => [
                'status' => 'disconnected',
                'connection_id' => null,
            ],
        ]);
    }
}
