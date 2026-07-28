<?php

namespace App\Models;

use App\Enums\Sell\SalePortfolioEventType;
use App\Enums\Sell\SalePortfolioStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SalePortfolioEvent extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'sale_portfolio_entry_id',
        'previous_event_id',
        'actor_user_id',
        'sequence',
        'event_type',
        'prior_status',
        'next_status',
        'marketplace_name',
        'marketplace_key',
        'external_listing_id',
        'external_listing_url',
        'advertised_price_minor',
        'advertised_currency_code',
        'reason_code',
        'note',
        'idempotency_key',
        'payload_hash',
        'input_snapshot',
        'occurred_at',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'event_type' => SalePortfolioEventType::class,
            'prior_status' => SalePortfolioStatus::class,
            'next_status' => SalePortfolioStatus::class,
            'advertised_price_minor' => 'integer',
            'input_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Sale portfolio events are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Sale portfolio events cannot be deleted individually.',
            );
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(
            SalePortfolioEntry::class,
            'sale_portfolio_entry_id',
        );
    }

    public function previousEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_event_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
