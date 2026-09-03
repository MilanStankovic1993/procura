<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ActualCostSnapshot extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owned_product_id',
        'previous_snapshot_id',
        'actor_user_id',
        'sequence',
        'reporting_currency_code',
        'known_count',
        'unknown_count',
        'known_reporting_total_minor',
        'correction_reason',
        'note',
        'idempotency_key',
        'payload_hash',
        'input_hash',
        'input_snapshot',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'known_count' => 'integer',
            'unknown_count' => 'integer',
            'known_reporting_total_minor' => 'integer',
            'input_snapshot' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Actual cost snapshots are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Actual cost snapshots cannot be deleted individually.');
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_snapshot_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ActualCostItem::class)->orderBy('position');
    }
}
