<?php

namespace App\Models;

use App\Enums\BrokerRequests\BrokerPaymentCaseOutcome;
use App\Enums\BrokerRequests\BrokerPaymentCaseStatus;
use App\Enums\BrokerRequests\BrokerPaymentCaseType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class BrokerPaymentCase extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'broker_request_id',
        'broker_request_offer_id',
        'broker_transaction_id',
        'source_transaction_event_id',
        'opened_by_user_id',
        'type',
        'status',
        'requested_amount_minor',
        'resolved_amount_minor',
        'currency_code',
        'resolution_outcome',
        'external_case_reference',
        'logical_case_hash',
        'current_event_id',
        'event_sequence',
        'opened_at',
        'resolved_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => BrokerPaymentCaseType::class,
            'status' => BrokerPaymentCaseStatus::class,
            'resolution_outcome' => BrokerPaymentCaseOutcome::class,
            'requested_amount_minor' => 'integer',
            'resolved_amount_minor' => 'integer',
            'event_sequence' => 'integer',
            'opened_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (BrokerPaymentCase $case): void {
            if ($case->isDirty([
                'organization_id',
                'broker_request_id',
                'broker_request_offer_id',
                'broker_transaction_id',
                'source_transaction_event_id',
                'opened_by_user_id',
                'type',
                'requested_amount_minor',
                'currency_code',
                'external_case_reference',
                'logical_case_hash',
                'opened_at',
            ])) {
                throw new LogicException(
                    'Broker payment-case source evidence is immutable.',
                );
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Broker payment cases cannot be deleted.');
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

    public function brokerTransaction(): BelongsTo
    {
        return $this->belongsTo(BrokerTransaction::class);
    }

    public function sourceTransactionEvent(): BelongsTo
    {
        return $this->belongsTo(
            BrokerTransactionEvent::class,
            'source_transaction_event_id',
        );
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function currentEvent(): BelongsTo
    {
        return $this->belongsTo(
            BrokerPaymentCaseEvent::class,
            'current_event_id',
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(BrokerPaymentCaseEvent::class)
            ->orderByDesc('sequence');
    }
}
