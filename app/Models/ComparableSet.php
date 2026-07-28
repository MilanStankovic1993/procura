<?php

namespace App\Models;

use App\Enums\Comparables\ComparableSetStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ComparableSet extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'product_match_id',
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
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Comparable selection evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Comparable selection evidence cannot be deleted individually.');
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function productMatch(): BelongsTo
    {
        return $this->belongsTo(ProductMatch::class);
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
        return $this->hasMany(ComparableSetItem::class)
            ->orderByRaw(
                'case when `comparable_set_items`.`rank` is null then 1 else 0 end',
            )
            ->orderBy('rank')
            ->orderBy('id');
    }

    public function priceEstimates(): HasMany
    {
        return $this->hasMany(PriceEstimate::class)->orderByDesc('run_number');
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class)->orderByDesc('run_number');
    }
}
