<?php

namespace App\BrokerRequests;

use App\Models\BrokerCommission;
use App\Models\BrokerRequest;
use App\Models\BrokerRequestOffer;
use App\Models\BrokerTransaction;

final class BrokerReportSnapshot
{
    /**
     * Build the subject-safe, immutable source used for both evidence hashing
     * and PDF rendering. Operator evidence and supplier-private references
     * deliberately never enter this projection.
     *
     * @return array<string, mixed>
     */
    public static function build(
        BrokerRequest $request,
        BrokerRequestOffer $offer,
        BrokerTransaction $transaction,
        BrokerCommission $commission,
        string $version,
        string $locale,
        string $generatedAt,
    ): array {
        return [
            'version' => $version,
            'locale' => $locale,
            'generated_at' => $generatedAt,
            'request' => [
                'id' => $request->getKey(),
                'title' => $request->title,
                'product_description' => $request->product_description,
                'brand_preference' => $request->brand_preference,
                'model_preference' => $request->model_preference,
                'condition_preference' => (
                    $request->condition_preference->value
                ),
                'quantity' => $request->quantity,
                'budget_max_minor' => $request->budget_max_minor,
                'budget_currency_code' => $request->budget_currency_code,
                'target_country_codes' => $request->target_country_codes,
                'needed_by' => $request->needed_by?->toDateString(),
            ],
            'offer' => [
                'id' => $offer->getKey(),
                'supplier_display_name' => $offer->supplier_display_name,
                'item_description' => $offer->item_description,
                'condition' => $offer->condition->value,
                'quantity' => $offer->quantity,
                'unit_price_minor' => $offer->unit_price_minor,
                'item_subtotal_minor' => $offer->item_subtotal_minor,
                'shipping_cost_minor' => $offer->shipping_cost_minor,
                'tax_duty_cost_minor' => $offer->tax_duty_cost_minor,
                'other_cost_minor' => $offer->other_cost_minor,
                'total_minor' => $offer->total_minor,
                'commission_rule_version' => (
                    $offer->commission_rule_version
                ),
                'commission_rate_basis_points' => (
                    $offer->commission_rate_basis_points
                ),
                'commission_base_minor' => $offer->commission_base_minor,
                'commission_amount_minor' => (
                    $offer->commission_amount_minor
                ),
                'payable_total_minor' => $offer->payable_total_minor,
                'currency_code' => $offer->currency_code,
                'origin_country_code' => $offer->origin_country_code,
                'estimated_delivery_date' => (
                    $offer->estimated_delivery_date?->toDateString()
                ),
                'warranty_months' => $offer->warranty_months,
                'return_policy_summary' => $offer->return_policy_summary,
            ],
            'transaction' => [
                'id' => $transaction->getKey(),
                'status' => $transaction->status->value,
                'opened_at' => $transaction->opened_at?->toIso8601String(),
                'payment_confirmed_at' => (
                    $transaction->payment_confirmed_at?->toIso8601String()
                ),
                'ordered_at' => $transaction->ordered_at?->toIso8601String(),
                'shipped_at' => $transaction->shipped_at?->toIso8601String(),
                'delivered_at' => (
                    $transaction->delivered_at?->toIso8601String()
                ),
                'completed_at' => (
                    $transaction->completed_at?->toIso8601String()
                ),
                'timeline' => $transaction->events
                    ->sortBy('sequence')
                    ->values()
                    ->map(static fn ($event): array => [
                        'sequence' => $event->sequence,
                        'event_type' => $event->event_type->value,
                        'from_status' => $event->from_status?->value,
                        'to_status' => $event->to_status->value,
                        'occurred_at' => $event->occurred_at->toIso8601String(),
                    ])
                    ->all(),
            ],
            'commission' => [
                'id' => $commission->getKey(),
                'status' => $commission->status->value,
                'rule_version' => $commission->rule_version,
                'rate_basis_points' => $commission->rate_basis_points,
                'base_minor' => $commission->base_minor,
                'amount_minor' => $commission->amount_minor,
                'currency_code' => $commission->currency_code,
                'recorded_at' => (
                    $commission->recorded_at?->toIso8601String()
                ),
                'earned_at' => $commission->earned_at?->toIso8601String(),
                'settled_at' => $commission->settled_at?->toIso8601String(),
            ],
        ];
    }
}
