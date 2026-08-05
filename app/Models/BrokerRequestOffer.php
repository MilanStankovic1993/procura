<?php

namespace App\Models;

use App\Enums\BrokerRequests\BrokerProductCondition;
use App\Enums\BrokerRequests\BrokerRequestOfferStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class BrokerRequestOffer extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'broker_request_id',
        'presented_by_user_id',
        'status',
        'supplier_display_name',
        'supplier_reference',
        'item_description',
        'condition',
        'quantity',
        'unit_price_minor',
        'item_subtotal_minor',
        'shipping_cost_minor',
        'tax_duty_cost_minor',
        'other_cost_minor',
        'total_minor',
        'commission_rule_version',
        'commission_rate_basis_points',
        'commission_base_minor',
        'commission_amount_minor',
        'payable_total_minor',
        'currency_code',
        'origin_country_code',
        'estimated_delivery_date',
        'valid_until',
        'warranty_months',
        'return_policy_summary',
        'source_request_event_id',
        'current_event_id',
        'event_sequence',
        'presented_at',
        'accepted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BrokerRequestOfferStatus::class,
            'condition' => BrokerProductCondition::class,
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'item_subtotal_minor' => 'integer',
            'shipping_cost_minor' => 'integer',
            'tax_duty_cost_minor' => 'integer',
            'other_cost_minor' => 'integer',
            'total_minor' => 'integer',
            'commission_rate_basis_points' => 'integer',
            'commission_base_minor' => 'integer',
            'commission_amount_minor' => 'integer',
            'payable_total_minor' => 'integer',
            'estimated_delivery_date' => 'immutable_date',
            'valid_until' => 'immutable_datetime',
            'warranty_months' => 'integer',
            'event_sequence' => 'integer',
            'presented_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (BrokerRequestOffer $offer): void {
            if ($offer->isDirty([
                'organization_id',
                'broker_request_id',
                'presented_by_user_id',
                'supplier_display_name',
                'supplier_reference',
                'item_description',
                'condition',
                'quantity',
                'unit_price_minor',
                'item_subtotal_minor',
                'shipping_cost_minor',
                'tax_duty_cost_minor',
                'other_cost_minor',
                'total_minor',
                'commission_rule_version',
                'commission_rate_basis_points',
                'commission_base_minor',
                'commission_amount_minor',
                'payable_total_minor',
                'currency_code',
                'origin_country_code',
                'estimated_delivery_date',
                'valid_until',
                'warranty_months',
                'return_policy_summary',
                'source_request_event_id',
                'presented_at',
            ])) {
                throw new LogicException(
                    'Presented broker-offer terms are immutable.',
                );
            }
        });
    }

    public function brokerRequest(): BelongsTo
    {
        return $this->belongsTo(BrokerRequest::class);
    }

    public function presentedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presented_by_user_id');
    }

    public function sourceRequestEvent(): BelongsTo
    {
        return $this->belongsTo(
            BrokerRequestEvent::class,
            'source_request_event_id',
        );
    }

    public function currentEvent(): BelongsTo
    {
        return $this->belongsTo(
            BrokerRequestOfferEvent::class,
            'current_event_id',
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(BrokerRequestOfferEvent::class)
            ->orderByDesc('sequence');
    }

    public function brokerTransaction(): HasOne
    {
        return $this->hasOne(BrokerTransaction::class);
    }
}
