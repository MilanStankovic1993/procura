<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Comparables\MarketCompatibilityStatus;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\OwnedProducts\OwnedProductAssessmentStatus;
use App\Enums\Pricing\ExchangeRateResolutionStatus;
use App\Enums\Sell\SellPriceIntelligenceMetricOperation;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Currency;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\SellComparableMarketNormalization;
use App\Models\SellComparableRecord;
use App\Models\User;
use App\OwnedProductAssessment\CurrentOwnedProductAssessmentResolver;
use App\Pricing\Contracts\ExchangeRateResolver;
use App\Pricing\MinorMoneyConverter;
use App\SellPriceIntelligence\Metrics\SellPriceIntelligenceMetrics;
use App\SellPriceIntelligence\Metrics\SellPriceIntelligenceMetricTimer;
use App\Support\Validation\ApplicationValidation;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CreateSellComparableMarketNormalization
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly CurrentOwnedProductAssessmentResolver $currentAssessment,
        private readonly ExchangeRateResolver $exchangeRates,
        private readonly MinorMoneyConverter $money,
        private readonly RefreshSellPriceIntelligence $priceIntelligence,
        private readonly SellPriceIntelligenceMetrics $priceIntelligenceMetrics,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(
        Organization $organization,
        User $actor,
        string $ownedProductId,
        string $comparableId,
        array $attributes,
    ): array {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $ownedProductId,
            $comparableId,
            $attributes,
        ): array {
            if (($attributes['evidence_confirmed'] ?? false) !== true) {
                ApplicationValidation::fail(
                    'evidence_confirmed',
                    ApplicationValidationCode::MarketNormalizationEvidenceConfirmationRequired,
                );
            }

            $this->authorizer->authorize(
                $organization,
                $actor,
                OrganizationPermission::ManageOwnedProducts,
                lockForUpdate: true,
            );
            $ownedProduct = OwnedProduct::query()
                ->forOrganization($organization)
                ->lockForUpdate()
                ->findOrFail($ownedProductId);
            $assessment = $this->currentAssessment->resolve(
                $ownedProduct,
                lockForUpdate: true,
            );
            $comparable = SellComparableRecord::query()
                ->forOrganization($organization)
                ->where('owned_product_id', $ownedProduct->getKey())
                ->lockForUpdate()
                ->findOrFail($comparableId);

            if (
                $assessment === null
                || $assessment->status !== OwnedProductAssessmentStatus::Ready
                || $assessment->matcher_status !== ProductMatchStatus::Matched
                || $assessment->product_model_id === null
                || $comparable->owned_product_assessment_id
                    !== $assessment->getKey()
                || $comparable->assessment_input_hash
                    !== $assessment->input_hash
                || $comparable->product_model_id
                    !== $assessment->product_model_id
            ) {
                ApplicationValidation::fail(
                    'comparable',
                    ApplicationValidationCode::SellComparableNormalizationAssessmentMismatch,
                );
            }

            $targetCountryCode = strtoupper(
                (string) $attributes['target_country_code'],
            );
            $targetCurrencyCode = strtoupper(
                (string) $attributes['target_currency_code'],
            );
            $snapshot = $assessment->snapshot()->firstOrFail();

            if (
                ! in_array(
                    $targetCountryCode,
                    $snapshot->target_country_codes,
                    true,
                )
            ) {
                ApplicationValidation::fail(
                    'target_country_code',
                    ApplicationValidationCode::SellComparableNormalizationTargetCountryInvalid,
                );
            }

            if (
                $comparable->country_code === $targetCountryCode
                && $comparable->currency_code === $targetCurrencyCode
            ) {
                ApplicationValidation::fail(
                    'comparable',
                    ApplicationValidationCode::SellComparableNormalizationUnnecessary,
                );
            }

            $status = MarketCompatibilityStatus::from(
                (string) $attributes['compatibility_status'],
            );
            $observedAt = CarbonImmutable::parse(
                (string) $attributes['observed_at'],
            )->utc();
            $calculationVersion = (string) config(
                'sell_price_intelligence.normalization_calculation_version',
            );
            $facts = [
                'compatibility_status' => $status->value,
                'source_country_code' => $comparable->country_code,
                'target_country_code' => $targetCountryCode,
                'source_currency_code' => $comparable->currency_code,
                'target_currency_code' => $targetCurrencyCode,
                'source_amount_minor' => $comparable->asking_price_minor,
                'market_factor_basis_points' => null,
                'shipping_minor' => 0,
                'import_duty_minor' => 0,
                'tax_minor' => 0,
                'other_cost_minor' => 0,
                'evidence_reference' => trim(
                    (string) $attributes['evidence_reference'],
                ),
                'compatibility_note' => trim(
                    (string) $attributes['compatibility_note'],
                ),
                'observed_at' => $observedAt->toIso8601String(),
            ];
            $calculated = [
                'exchange_rate_id' => null,
                'converted_amount_minor' => null,
                'market_adjusted_amount_minor' => null,
                'normalized_amount_minor' => null,
                'rate_direction' => null,
                'rate_value' => null,
                'rate_effective_at' => null,
                'rate_provider' => null,
                'rate_provider_reference' => null,
                'reason_codes' => ['regional_compatibility_rejected'],
            ];

            if ($status === MarketCompatibilityStatus::Compatible) {
                $facts = [
                    ...$facts,
                    'market_factor_basis_points' => (int) (
                        $attributes['market_factor_basis_points']
                    ),
                    'shipping_minor' => (int) (
                        $attributes['shipping_minor'] ?? 0
                    ),
                    'import_duty_minor' => (int) (
                        $attributes['import_duty_minor'] ?? 0
                    ),
                    'tax_minor' => (int) ($attributes['tax_minor'] ?? 0),
                    'other_cost_minor' => (int) (
                        $attributes['other_cost_minor'] ?? 0
                    ),
                ];
                $calculated = $this->calculate(
                    $comparable,
                    $targetCurrencyCode,
                    $observedAt,
                    $facts,
                );
            }

            $evidence = [
                'owned_product_id' => $ownedProduct->getKey(),
                'owned_product_assessment_id' => $assessment->getKey(),
                'assessment_input_hash' => $assessment->input_hash,
                'sell_comparable_record_id' => $comparable->getKey(),
                'comparable_evidence_hash' => $comparable->evidence_hash,
                'calculation_version' => $calculationVersion,
                ...$facts,
                ...$calculated,
                'evidence_confirmed' => true,
            ];
            $encodedEvidence = json_encode(
                $evidence,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
            $evidenceHash = hash('sha256', $encodedEvidence);
            $normalizationKey = hash('sha256', implode('|', [
                $organization->getKey(),
                $ownedProduct->getKey(),
                $assessment->getKey(),
                $comparable->getKey(),
                $targetCountryCode,
                $targetCurrencyCode,
                $calculationVersion,
                $evidenceHash,
            ]));
            $normalization = SellComparableMarketNormalization::query()
                ->firstOrCreate(
                    ['normalization_key' => $normalizationKey],
                    [
                        'organization_id' => $organization->getKey(),
                        'owned_product_id' => $ownedProduct->getKey(),
                        'owned_product_assessment_id' => $assessment->getKey(),
                        'sell_comparable_record_id' => $comparable->getKey(),
                        'created_by_user_id' => $actor->getKey(),
                        'normalization_key' => $normalizationKey,
                        'evidence_hash' => $evidenceHash,
                        'calculation_version' => $calculationVersion,
                        ...$facts,
                        ...$calculated,
                        'raw_evidence' => [
                            'submitted' => $attributes,
                            'calculated' => $evidence,
                        ],
                        'observed_at' => $observedAt,
                    ],
                );
            $created = $normalization->wasRecentlyCreated;
            $metric = SellPriceIntelligenceMetricTimer::start(
                SellPriceIntelligenceMetricOperation::NormalizationRecalculation,
            );
            $refreshed = $this->priceIntelligence->refresh(
                $ownedProduct,
                $assessment,
                $targetCountryCode,
                $targetCurrencyCode,
                $metric,
            );
            $metric->finish();
            $this->priceIntelligenceMetrics->recordAfterCommit($metric);

            return [
                'normalization' => $normalization->load([
                    'comparableRecord',
                    'exchangeRate',
                    'createdBy',
                ]),
                ...$refreshed,
                'created' => $created,
            ];
        }, attempts: 3);
    }

    /** @param array<string, mixed> $facts */
    private function calculate(
        SellComparableRecord $comparable,
        string $targetCurrencyCode,
        CarbonImmutable $observedAt,
        array $facts,
    ): array {
        $currencies = Currency::query()
            ->whereIn('code', [
                $comparable->currency_code,
                $targetCurrencyCode,
            ])
            ->get()
            ->keyBy('code');
        $sourceCurrency = $currencies->get($comparable->currency_code);
        $targetCurrency = $currencies->get($targetCurrencyCode);

        if ($sourceCurrency === null || $targetCurrency === null) {
            ApplicationValidation::fail(
                'currency',
                ApplicationValidationCode::MarketNormalizationCurrencyMetadataRequired,
            );
        }

        $resolution = $this->exchangeRates->resolve(
            $comparable->currency_code,
            $targetCurrencyCode,
            $observedAt,
        );

        if ($resolution->status !== ExchangeRateResolutionStatus::Resolved) {
            ApplicationValidation::fail(
                'exchange_rate',
                $resolution->status === ExchangeRateResolutionStatus::Stale
                    ? ApplicationValidationCode::MarketNormalizationExchangeRateStale
                    : ApplicationValidationCode::MarketNormalizationExchangeRateMissing,
            );
        }

        $convertedAmount = $this->money->convert(
            $comparable->asking_price_minor,
            $sourceCurrency->minor_unit,
            $targetCurrency->minor_unit,
            (string) $resolution->rateValue,
        );
        $marketAdjustedAmount = BigInteger::of($convertedAmount)
            ->multipliedBy((int) $facts['market_factor_basis_points'])
            ->dividedBy(10000, RoundingMode::HalfEven)
            ->toInt();
        $normalizedAmount = BigInteger::of($marketAdjustedAmount)
            ->plus((int) $facts['shipping_minor'])
            ->plus((int) $facts['import_duty_minor'])
            ->plus((int) $facts['tax_minor'])
            ->plus((int) $facts['other_cost_minor']);
        $maximum = BigInteger::of(
            (string) config('market_normalization.maximum_cost_minor'),
        );

        if ($normalizedAmount->isGreaterThan($maximum)) {
            ApplicationValidation::fail(
                'costs',
                ApplicationValidationCode::MarketNormalizationAmountLimit,
            );
        }

        $reasonCodes = [
            'regional_compatibility_confirmed',
            $resolution->reasonCode,
            'market_factor_applied',
        ];

        foreach ([
            'shipping_minor' => 'shipping_cost_applied',
            'import_duty_minor' => 'import_duty_applied',
            'tax_minor' => 'tax_cost_applied',
            'other_cost_minor' => 'other_market_cost_applied',
        ] as $field => $reason) {
            if ((int) $facts[$field] > 0) {
                $reasonCodes[] = $reason;
            }
        }

        return [
            'exchange_rate_id' => $resolution->exchangeRateId,
            'converted_amount_minor' => $convertedAmount,
            'market_adjusted_amount_minor' => $marketAdjustedAmount,
            'normalized_amount_minor' => $normalizedAmount->toInt(),
            'rate_direction' => $resolution->direction->value,
            'rate_value' => $resolution->rateValue,
            'rate_effective_at' => $resolution->effectiveAt,
            'rate_provider' => $resolution->provider,
            'rate_provider_reference' => $resolution->providerReference,
            'reason_codes' => $reasonCodes,
        ];
    }
}
