<?php

namespace App\Models;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Analyses\AnalysisType;
use App\Enums\Opportunity\OpportunityComponent;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class Analysis extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'listing_id',
        'listing_snapshot_id',
        'requested_by_user_id',
        'analysis_type',
        'status',
        'source_country_code',
        'target_country_code',
        'pipeline_version',
        'request_payload',
        'request_hash',
        'result_payload',
        'processing_attempts',
        'submitted_at',
        'processing_started_at',
        'finished_at',
        'failed_at',
        'archived_at',
        'next_retry_at',
        'last_error_code',
        'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'analysis_type' => AnalysisType::class,
            'status' => AnalysisStatus::class,
            'request_payload' => 'array',
            'result_payload' => 'array',
            'processing_attempts' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Analysis $analysis): void {
            $immutable = [
                'organization_id',
                'listing_id',
                'listing_snapshot_id',
                'requested_by_user_id',
                'analysis_type',
                'source_country_code',
                'target_country_code',
                'pipeline_version',
                'request_payload',
                'request_hash',
            ];

            if ($analysis->isDirty($immutable)) {
                throw new LogicException('Analysis request data is immutable after creation.');
            }
        });
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function listingSnapshot(): BelongsTo
    {
        return $this->belongsTo(ListingSnapshot::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function sourceCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'source_country_code');
    }

    public function targetCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'target_country_code');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(AnalysisDispatch::class)->orderByDesc('run_number');
    }

    public function retryEvents(): HasMany
    {
        return $this->hasMany(AnalysisRetryEvent::class)
            ->orderByDesc('run_number');
    }

    public function currentRetryEvent(): HasOne
    {
        return $this->hasOne(AnalysisRetryEvent::class)
            ->latestOfMany('run_number');
    }

    public function currentDispatch(): HasOne
    {
        return $this->hasOne(AnalysisDispatch::class)->latestOfMany('run_number');
    }

    public function aiAnalyses(): HasMany
    {
        return $this->hasMany(AiAnalysis::class)->orderByDesc('attempt_number');
    }

    public function productMatches(): HasMany
    {
        return $this->hasMany(ProductMatch::class)->orderByDesc('run_number');
    }

    public function currentProductMatch(): HasOne
    {
        return $this->hasOne(ProductMatch::class)->latestOfMany('run_number');
    }

    public function comparableSets(): HasMany
    {
        return $this->hasMany(ComparableSet::class)->orderByDesc('run_number');
    }

    public function comparableMarketNormalizations(): HasMany
    {
        return $this->hasMany(ComparableMarketNormalization::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function currentComparableSet(): HasOne
    {
        return $this->hasOne(ComparableSet::class)->latestOfMany('run_number');
    }

    public function priceEstimates(): HasMany
    {
        return $this->hasMany(PriceEstimate::class)->orderByDesc('run_number');
    }

    public function currentPriceEstimate(): HasOne
    {
        return $this->hasOne(PriceEstimate::class)->latestOfMany('run_number');
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class)->orderByDesc('run_number');
    }

    public function currentRiskAssessment(): HasOne
    {
        return $this->hasOne(RiskAssessment::class)->latestOfMany('run_number');
    }

    public function costInputs(): HasMany
    {
        return $this->hasMany(CostInput::class)->orderByDesc('run_number');
    }

    public function currentCostInput(): HasOne
    {
        return $this->hasOne(CostInput::class)->latestOfMany('run_number');
    }

    public function profitEstimates(): HasMany
    {
        return $this->hasMany(ProfitEstimate::class)->orderByDesc('run_number');
    }

    public function currentProfitEstimate(): HasOne
    {
        return $this->hasOne(ProfitEstimate::class)->latestOfMany('run_number');
    }

    public function opportunityInputs(): HasMany
    {
        return $this->hasMany(OpportunityInput::class)
            ->orderByDesc('run_number');
    }

    public function currentOpportunityInput(): HasOne
    {
        return $this->hasOne(OpportunityInput::class)
            ->latestOfMany('run_number');
    }

    public function opportunityAssessments(): HasMany
    {
        return $this->hasMany(OpportunityAssessment::class)
            ->orderByDesc('run_number');
    }

    public function currentLogisticsAssessment(): HasOne
    {
        return $this->hasOne(OpportunityAssessment::class)
            ->ofMany(
                ['run_number' => 'max', 'id' => 'max'],
                static fn ($query) => $query->where(
                    'component',
                    OpportunityComponent::Logistics->value,
                ),
            );
    }

    public function currentDemandAssessment(): HasOne
    {
        return $this->hasOne(OpportunityAssessment::class)
            ->ofMany(
                ['run_number' => 'max', 'id' => 'max'],
                static fn ($query) => $query->where(
                    'component',
                    OpportunityComponent::Demand->value,
                ),
            );
    }

    public function dealScores(): HasMany
    {
        return $this->hasMany(DealScore::class)->orderByDesc('run_number');
    }

    public function currentDealScore(): HasOne
    {
        return $this->hasOne(DealScore::class)->latestOfMany('run_number');
    }

    public function buyerDecisionEvents(): HasMany
    {
        return $this->hasMany(BuyerDecisionEvent::class)
            ->orderByDesc('sequence');
    }

    public function currentBuyerDecisionEvent(): HasOne
    {
        return $this->hasOne(BuyerDecisionEvent::class)
            ->latestOfMany('sequence');
    }

    public function outcomeEstimateAttributions(): HasMany
    {
        return $this->hasMany(OutcomeEstimateAttribution::class);
    }

    public function estimateAccuracyReports(): HasMany
    {
        return $this->hasMany(EstimateAccuracyReport::class);
    }
}
