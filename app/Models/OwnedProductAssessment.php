<?php

namespace App\Models;

use App\Enums\Catalog\ProductMatchReviewStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\OwnedProducts\OwnedProductAssessmentStatus;
use App\Enums\OwnedProducts\OwnedProductCondition;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class OwnedProductAssessment extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owned_product_id',
        'owned_product_snapshot_id',
        'assessed_by_user_id',
        'run_number',
        'status',
        'matcher_status',
        'review_status',
        'method',
        'matcher_version',
        'evaluator_version',
        'snapshot_content_hash',
        'image_evidence_hash',
        'input_hash',
        'assessment_key',
        'confidence_basis_points',
        'completeness_basis_points',
        'product_category_id',
        'product_model_id',
        'product_variant_id',
        'identified_brand_name',
        'identified_model_name',
        'identified_variant_name',
        'condition',
        'included_accessories',
        'missing_accessories',
        'defects',
        'candidate_snapshot',
        'reason_codes',
        'unknown_facts',
        'verification_actions',
        'input_snapshot',
        'assessed_at',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'status' => OwnedProductAssessmentStatus::class,
            'matcher_status' => ProductMatchStatus::class,
            'review_status' => ProductMatchReviewStatus::class,
            'confidence_basis_points' => 'integer',
            'completeness_basis_points' => 'integer',
            'condition' => OwnedProductCondition::class,
            'included_accessories' => 'array',
            'missing_accessories' => 'array',
            'defects' => 'array',
            'candidate_snapshot' => 'array',
            'reason_codes' => 'array',
            'unknown_facts' => 'array',
            'verification_actions' => 'array',
            'input_snapshot' => 'array',
            'assessed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Owned-product assessments are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Owned-product assessments cannot be deleted individually.',
            );
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(
            OwnedProductSnapshot::class,
            'owned_product_snapshot_id',
        );
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function sellComparableRecords(): HasMany
    {
        return $this->hasMany(
            SellComparableRecord::class,
            'owned_product_assessment_id',
        );
    }

    public function sellComparableSelections(): HasMany
    {
        return $this->hasMany(
            SellComparableSelection::class,
            'owned_product_assessment_id',
        );
    }

    public function sellPriceBands(): HasMany
    {
        return $this->hasMany(
            SellPriceBand::class,
            'owned_product_assessment_id',
        );
    }

    public function sellComparableMarketNormalizations(): HasMany
    {
        return $this->hasMany(
            SellComparableMarketNormalization::class,
            'owned_product_assessment_id',
        );
    }

    public function sellListingDrafts(): HasMany
    {
        return $this->hasMany(
            SellListingDraft::class,
            'owned_product_assessment_id',
        );
    }
}
