<?php

namespace App\Models;

use App\Enums\BrokerRequests\BrokerCommissionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class BrokerCommission extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'broker_request_id',
        'broker_request_offer_id',
        'broker_transaction_id',
        'status',
        'rule_version',
        'rate_basis_points',
        'base_minor',
        'amount_minor',
        'currency_code',
        'source_transaction_event_id',
        'current_event_id',
        'event_sequence',
        'recorded_at',
        'earned_at',
        'settled_at',
        'waived_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BrokerCommissionStatus::class,
            'rate_basis_points' => 'integer',
            'base_minor' => 'integer',
            'amount_minor' => 'integer',
            'event_sequence' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'earned_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'waived_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (BrokerCommission $commission): void {
            if ($commission->isDirty([
                'organization_id',
                'broker_request_id',
                'broker_request_offer_id',
                'broker_transaction_id',
                'rule_version',
                'rate_basis_points',
                'base_minor',
                'amount_minor',
                'currency_code',
                'source_transaction_event_id',
                'recorded_at',
            ])) {
                throw new LogicException(
                    'Broker-commission terms are immutable.',
                );
            }
        });
    }

    public function brokerTransaction(): BelongsTo
    {
        return $this->belongsTo(BrokerTransaction::class);
    }

    public function brokerRequest(): BelongsTo
    {
        return $this->belongsTo(BrokerRequest::class);
    }

    public function brokerRequestOffer(): BelongsTo
    {
        return $this->belongsTo(BrokerRequestOffer::class);
    }

    public function currentEvent(): BelongsTo
    {
        return $this->belongsTo(
            BrokerCommissionEvent::class,
            'current_event_id',
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(BrokerCommissionEvent::class)
            ->orderByDesc('sequence');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(BrokerReport::class)
            ->orderByDesc('sequence');
    }
}
