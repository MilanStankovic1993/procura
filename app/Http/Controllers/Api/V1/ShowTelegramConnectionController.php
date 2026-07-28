<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Monitoring\TelegramConnectionStatus;
use App\Enums\Subscriptions\FeatureCode;
use App\Http\Controllers\Controller;
use App\Models\TelegramConnection;
use App\Monitoring\Telegram\TelegramConfiguration;
use App\Subscriptions\SubscriptionEntitlements;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowTelegramConnectionController extends Controller
{
    public function __invoke(
        Request $request,
        OrganizationContext $context,
        SubscriptionEntitlements $entitlements,
        TelegramConfiguration $configuration,
    ): JsonResponse {
        $connection = TelegramConnection::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->whereIn('status', [
                TelegramConnectionStatus::Pending,
                TelegramConnectionStatus::Connected,
            ])
            ->latest('created_at')
            ->first();

        $entitled = $entitlements
            ->feature(
                $context->organization(),
                FeatureCode::TelegramNotifications,
            )
            ->is_enabled;

        return response()->json([
            'data' => $this->projection(
                $connection,
                $configuration,
                $entitled,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(
        ?TelegramConnection $connection,
        TelegramConfiguration $configuration,
        bool $entitled,
    ): array {
        $pendingIsCurrent = (
            $connection?->status === TelegramConnectionStatus::Pending
            && $connection->challenge_expires_at->isFuture()
        );
        $connected = (
            $connection?->status === TelegramConnectionStatus::Connected
        );

        return [
            'available' => $configuration->isConfigured(),
            'entitled' => $entitled,
            'status' => match (true) {
                $connected => TelegramConnectionStatus::Connected->value,
                $pendingIsCurrent => TelegramConnectionStatus::Pending->value,
                default => 'disconnected',
            },
            'connection_id' => (
                $connected || $pendingIsCurrent
                    ? $connection?->getKey()
                    : null
            ),
            'bot_username' => (
                $connection?->bot_username
                ?: $configuration->botUsername()
            ),
            'challenge_expires_at' => $pendingIsCurrent
                ? $connection?->challenge_expires_at?->toIso8601String()
                : null,
            'connected_at' => $connected
                ? $connection?->connected_at?->toIso8601String()
                : null,
            'can_enable_delivery' => (
                $configuration->isConfigured()
                && $entitled
                && $connected
            ),
        ];
    }
}
