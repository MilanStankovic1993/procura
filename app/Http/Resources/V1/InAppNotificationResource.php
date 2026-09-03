<?php

namespace App\Http\Resources\V1;

use App\Enums\Monitoring\NotificationEventType;
use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Alert */
class InAppNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $current = $this->currentInAppLog;
        $currentEmail = $this->currentEmailLog;
        $emailDelivery = $currentEmail?->payload['delivery'] ?? [];
        $currentTelegram = $this->currentTelegramLog;
        $telegramDelivery = (
            $currentTelegram?->payload['delivery'] ?? []
        );

        return [
            'id' => $this->getKey(),
            'alert_type' => $this->alert_type->value,
            'saved_search' => [
                'id' => $this->saved_search_id,
                'title' => $this->savedSearch?->title,
            ],
            'listing' => [
                'id' => $this->listing_id,
                'title' => $this->listing?->title,
                'asking_price_minor' => $this->listing?->asking_price_minor,
                'currency_code' => $this->listing?->currency_code,
                'source_country_code' => $this->listing?->source_country_code,
            ],
            'payload' => $this->payload,
            'current_log_id' => $current?->getKey(),
            'state' => $current?->event_type->value,
            'read' => $current?->event_type === NotificationEventType::Read,
            'triggered_at' => $this->triggered_at?->toIso8601String(),
            'state_changed_at' => $current?->occurred_at?->toIso8601String(),
            'delivery' => [
                'email' => $currentEmail === null
                    ? null
                    : [
                        'state' => $currentEmail->event_type->value,
                        'attempt' => $emailDelivery['attempt'] ?? null,
                        'reason_code' => (
                            $emailDelivery['reason_code'] ?? null
                        ),
                        'occurred_at' => (
                            $currentEmail->occurred_at?->toIso8601String()
                        ),
                    ],
                'telegram' => $currentTelegram === null
                    ? null
                    : [
                        'state' => $currentTelegram->event_type->value,
                        'attempt' => $telegramDelivery['attempt'] ?? null,
                        'reason_code' => (
                            $telegramDelivery['reason_code'] ?? null
                        ),
                        'occurred_at' => (
                            $currentTelegram
                                ->occurred_at
                                ?->toIso8601String()
                        ),
                    ],
            ],
        ];
    }
}
