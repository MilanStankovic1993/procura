<?php

namespace App\Models;

use App\Enums\Comparables\ComparableSetStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SellComparableSelection extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owned_product_id',
        'owned_product_assessment_id',
        'run_number',
        'status',
        'selector_version',
        'input_hash',
        'selection_key',
        'target_country_code',
        'target_currency_code',
        'candidate_count',
        'included_count',
        'excluded_count',
        'minimum_required',
        'reason_codes',
        'input_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'status' => ComparableSetStatus::class,
            'candidate_count' => 'integer',
            'included_count' => 'integer',
            'excluded_count' => 'integer',
            'minimum_required' => 'integer',
            'reason_codes' => 'array',
            'input_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Sell comparable selections are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Sell comparable selections cannot be deleted individually.',
            );
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(
            OwnedProductAssessment::class,
            'owned_product_assessment_id',
        );
    }

    public function targetCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'target_country_code');
    }

    public function targetCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'target_currency_code');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SellComparableSelectionItem::class)
            ->orderByRaw(
                'case when `sell_comparable_selection_items`.`rank` is null then 1 else 0 end',
            )
            ->orderBy('rank')
            ->orderBy('id');
    }

    public function priceBands(): HasMany
    {
        return $this->hasMany(SellPriceBand::class)->orderByDesc('run_number');
    }
}
