<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CostInput extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'price_estimate_id',
        'risk_assessment_id',
        'submitted_by_user_id',
        'run_number',
        'input_version',
        'input_hash',
        'input_key',
        'currency_code',
        'source_country_code',
        'target_country_code',
        'regional_compatibility_confirmed',
        'known_count',
        'unknown_count',
        'input_snapshot',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'regional_compatibility_confirmed' => 'boolean',
            'known_count' => 'integer',
            'unknown_count' => 'integer',
            'input_snapshot' => 'array',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Cost-input evidence is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Cost-input evidence cannot be deleted individually.',
            );
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function priceEstimate(): BelongsTo
    {
        return $this->belongsTo(PriceEstimate::class);
    }

    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code');
    }

    public function sourceCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'source_country_code');
    }

    public function targetCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'target_country_code');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CostInputItem::class)->orderBy('position');
    }

    public function profitEstimates(): HasMany
    {
        return $this->hasMany(ProfitEstimate::class)->orderByDesc('run_number');
    }
}
