<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Monitoring\BeginTelegramConnection;
use App\Enums\Monitoring\TelegramConnectionStatus;
use App\Enums\Subscriptions\FeatureCode;
use App\Enums\Validation\ApplicationValidationCode;
use App\Http\Controllers\Controller;
use App\Subscriptions\SubscriptionEntitlements;
use App\Support\Validation\ApplicationValidation;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BeginTelegramConnectionController extends Controller
{
    public function __invoke(
        Request $request,
        BeginTelegramConnection $begin,
        OrganizationContext $context,
        SubscriptionEntitlements $entitlements,
    ): JsonResponse {
        if (! $entitlements
            ->feature(
                $context->organization(),
                FeatureCode::TelegramNotifications,
            )
            ->is_enabled) {
            ApplicationValidation::fail(
                'telegram',
                ApplicationValidationCode::TelegramNotificationsPlanDisabled,
            );
        }

        $connection = $begin->begin($request->user());
        $connected = (
            $connection->status === TelegramConnectionStatus::Connected
        );

        return response()->json([
            'data' => [
                'status' => $connection->status->value,
                'connection_id' => $connection->getKey(),
                'bot_username' => $connection->bot_username,
                'link_url' => $connected
                    ? null
                    : sprintf(
                        'https://t.me/%s?start=%s',
                        $connection->bot_username,
                        $connection->challenge_token,
                    ),
                'challenge_expires_at' => (
                    $connection->challenge_expires_at->toIso8601String()
                ),
                'connected_at' => (
                    $connection->connected_at?->toIso8601String()
                ),
            ],
        ]);
    }
}
