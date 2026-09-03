<?php

namespace App\Models;

use App\Enums\BrokerRequests\BrokerTransactionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class BrokerTransaction extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'broker_request_id',
        'broker_request_offer_id',
        'opened_by_user_id',
        'status',
        'supplier_total_minor',
        'commission_amount_minor',
        'payable_total_minor',
        'currency_code',
        'source_request_event_id',
        'source_offer_event_id',
        'current_event_id',
        'event_sequence',
        'opened_at',
        'payment_confirmed_at',
        'ordered_at',
        'shipped_at',
        'delivered_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BrokerTransactionStatus::class,
            'supplier_total_minor' => 'integer',
            'commission_amount_minor' => 'integer',
            'payable_total_minor' => 'integer',
            'event_sequence' => 'integer',
            'opened_at' => 'immutable_datetime',
            'payment_confirmed_at' => 'immutable_datetime',
            'ordered_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (BrokerTransaction $transaction): void {
            if ($transaction->isDirty([
                'organization_id',
                'broker_request_id',
                'broker_request_offer_id',
                'opened_by_user_id',
                'supplier_total_minor',
                'commission_amount_minor',
                'payable_total_minor',
                'currency_code',
                'source_request_event_id',
                'source_offer_event_id',
                'opened_at',
            ])) {
                throw new LogicException(
                    'Broker-transaction commercial terms are immutable.',
                );
            }
        });
    }

    public function brokerRequest(): BelongsTo
    {
        return $this->belongsTo(BrokerRequest::class);
    }

    public function brokerRequestOffer(): BelongsTo
    {
        return $this->belongsTo(BrokerRequestOffer::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function sourceRequestEvent(): BelongsTo
    {
        return $this->belongsTo(
            BrokerRequestEvent::class,
            'source_request_event_id',
        );
    }

    public function sourceOfferEvent(): BelongsTo
    {
        return $this->belongsTo(
            BrokerRequestOfferEvent::class,
            'source_offer_event_id',
        );
    }

    public function currentEvent(): BelongsTo
    {
        return $this->belongsTo(
            BrokerTransactionEvent::class,
            'current_event_id',
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(BrokerTransactionEvent::class)
            ->orderByDesc('sequence');
    }

    public function commission(): HasOne
    {
        return $this->hasOne(BrokerCommission::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(BrokerReport::class)
            ->orderByDesc('sequence');
    }

    public function paymentCases(): HasMany
    {
        return $this->hasMany(BrokerPaymentCase::class)
            ->orderByDesc('opened_at');
    }
}
