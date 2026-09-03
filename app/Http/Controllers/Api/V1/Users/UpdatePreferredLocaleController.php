<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Actions\Users\UpdatePreferredLocale;
use App\Enums\Localization\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Users\UpdatePreferredLocaleRequest;
use Illuminate\Http\JsonResponse;

class UpdatePreferredLocaleController extends Controller
{
    public function __invoke(
        UpdatePreferredLocaleRequest $request,
        UpdatePreferredLocale $updatePreferredLocale,
    ): JsonResponse {
        $user = $updatePreferredLocale->update(
            $request->user(),
            SupportedLocale::from($request->validated('preferred_locale')),
        );

        return response()->json([
            'data' => [
                'preferred_locale' => $user->preferred_locale->value,
            ],
        ]);
    }
}
