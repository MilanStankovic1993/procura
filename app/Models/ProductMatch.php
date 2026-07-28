<?php

namespace App\Models;

use App\Enums\Catalog\ProductMatchReviewStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ProductMatch extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'ai_analysis_id',
        'product_model_id',
        'product_variant_id',
        'run_number',
        'status',
        'review_status',
        'method',
        'matcher_version',
        'input_hash',
        'match_key',
        'confidence_basis_points',
        'candidate_snapshot',
        'reason_codes',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'status' => ProductMatchStatus::class,
            'review_status' => ProductMatchReviewStatus::class,
            'confidence_basis_points' => 'integer',
            'candidate_snapshot' => 'array',
            'reason_codes' => 'array',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ProductMatch $match): void {
            if ($match->isDirty([
                'organization_id',
                'analysis_id',
                'ai_analysis_id',
                'product_model_id',
                'product_variant_id',
                'run_number',
                'status',
                'method',
                'matcher_version',
                'input_hash',
                'match_key',
                'confidence_basis_points',
                'candidate_snapshot',
                'reason_codes',
            ])) {
                throw new LogicException('Product match evidence is immutable after creation.');
            }
        });
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function aiAnalysis(): BelongsTo
    {
        return $this->belongsTo(AiAnalysis::class);
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function comparableSets(): HasMany
    {
        return $this->hasMany(ComparableSet::class)->orderByDesc('run_number');
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class)->orderByDesc('run_number');
    }
}
