<?php

namespace App\Models;

use App\Enums\BrokerRequests\BrokerProductCondition;
use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class BrokerRequest extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'requester_user_id',
        'status',
        'title',
        'product_category_id',
        'product_description',
        'brand_preference',
        'model_preference',
        'condition_preference',
        'quantity',
        'budget_max_minor',
        'budget_currency_code',
        'target_country_codes',
        'needed_by',
        'notes',
        'current_event_id',
        'event_sequence',
        'submitted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BrokerRequestStatus::class,
            'condition_preference' => BrokerProductCondition::class,
            'quantity' => 'integer',
            'budget_max_minor' => 'integer',
            'target_country_codes' => 'array',
            'needed_by' => 'immutable_date',
            'event_sequence' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (BrokerRequest $request): void {
            if ($request->isDirty(['organization_id', 'requester_user_id'])) {
                throw new LogicException(
                    'Broker-request ownership is immutable after creation.',
                );
            }
        });
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function currentEvent(): BelongsTo
    {
        return $this->belongsTo(BrokerRequestEvent::class, 'current_event_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BrokerRequestEvent::class)
            ->orderByDesc('sequence');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(BrokerRequestOffer::class)
            ->orderBy('total_minor')
            ->orderBy('id');
    }

    public function brokerTransaction(): HasOne
    {
        return $this->hasOne(BrokerTransaction::class);
    }
}
