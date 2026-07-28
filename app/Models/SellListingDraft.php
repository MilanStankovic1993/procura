<?php

namespace App\Models;

use App\Enums\Sell\SellListingDraftStatus;
use App\Enums\Sell\SellPhotoReadinessStatus;
use App\Enums\Sell\SellPriceStrategy;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class SellListingDraft extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owned_product_id',
        'owned_product_assessment_id',
        'sell_price_band_id',
        'generated_by_user_id',
        'run_number',
        'status',
        'photo_readiness_status',
        'photo_readiness_basis_points',
        'listing_language',
        'template_version',
        'generator_version',
        'photo_evaluator_version',
        'assessment_input_hash',
        'price_band_input_hash',
        'image_evidence_hash',
        'input_hash',
        'draft_key',
        'generated_at',
        'target_country_code',
        'target_currency_code',
        'price_strategy',
        'target_asking_price_minor',
        'selected_band_low_minor',
        'selected_band_high_minor',
        'price_override_reason',
        'title',
        'description',
        'completeness_basis_points',
        'reason_codes',
        'unknown_facts',
        'warnings',
        'verification_actions',
        'source_fact_identifiers',
        'input_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'status' => SellListingDraftStatus::class,
            'photo_readiness_status' => SellPhotoReadinessStatus::class,
            'photo_readiness_basis_points' => 'integer',
            'generated_at' => 'immutable_datetime',
            'price_strategy' => SellPriceStrategy::class,
            'target_asking_price_minor' => 'integer',
            'selected_band_low_minor' => 'integer',
            'selected_band_high_minor' => 'integer',
            'completeness_basis_points' => 'integer',
            'reason_codes' => 'array',
            'unknown_facts' => 'array',
            'warnings' => 'array',
            'verification_actions' => 'array',
            'source_fact_identifiers' => 'array',
            'input_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Sell listing drafts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Sell listing drafts cannot be deleted individually.',
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

    public function priceBand(): BelongsTo
    {
        return $this->belongsTo(SellPriceBand::class, 'sell_price_band_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function facts(): HasMany
    {
        return $this->hasMany(SellListingDraftFact::class)->orderBy('position');
    }

    public function photoChecklist(): HasMany
    {
        return $this->hasMany(SellListingPhotoCheckItem::class)
            ->orderBy('position');
    }

    public function salePortfolioEntry(): HasOne
    {
        return $this->hasOne(SalePortfolioEntry::class);
    }
}
