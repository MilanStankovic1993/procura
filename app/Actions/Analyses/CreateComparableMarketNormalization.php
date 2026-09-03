<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Comparables\MarketCompatibilityStatus;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Pricing\ExchangeRateResolutionStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Analysis;
use App\Models\ComparableMarketNormalization;
use App\Models\ComparableRecord;
use App\Models\ComparableSet;
use App\Models\Currency;
use App\Models\Organization;
use App\Models\ProductMatch;
use App\Models\User;
use App\Pricing\Contracts\ExchangeRateResolver;
use App\Pricing\MinorMoneyConverter;
use App\Support\Validation\ApplicationValidation;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreateComparableMarketNormalization
{
    public function __construct(
        private readonly AnalysisAuthorizer $authorizer,
        private readonly ExchangeRateResolver $exchangeRates,
        private readonly MinorMoneyConverter $money,
        private readonly RefreshComparableSelection $selection,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{normalization: ComparableMarketNormalization, set: ComparableSet, created: bool}
     */
    public function create(
        Organization $organization,
        User $actor,
        Analysis $analysis,
        ComparableRecord $comparable,
        array $attributes,
    ): array {
        [$normalization, $match, $created] = DB::transaction(function () use (
            $organization,
            $actor,
            $analysis,
            $comparable,
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
                OrganizationPermission::ManageAnalyses,
                lockForUpdate: true,
            );
            $lockedAnalysis = Analysis::query()
                ->forOrganization($organization)
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedComparable = ComparableRecord::query()
                ->forOrganization($organization)
                ->lockForUpdate()
                ->findOrFail($comparable->getKey());
            $match = ProductMatch::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->first();

            if (! in_array($lockedAnalysis->status, [
                AnalysisStatus::NeedsInput,
                AnalysisStatus::Completed,
            ], true)) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::MarketNormalizationAnalysisNotFinished,
                );
            }

            if (
                $match === null
                || $match->status !== ProductMatchStatus::Matched
                || $match->product_model_id === null
                || $lockedComparable->product_model_id !== $match->product_model_id
            ) {
                ApplicationValidation::fail(
                    'comparable',
                    ApplicationValidationCode::MarketNormalizationComparableMismatch,
                );
            }

            $targetCurrencyCode = $this->targetCurrencyCode($lockedAnalysis);

            if ($targetCurrencyCode === null) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::MarketNormalizationTargetCurrencyRequired,
                );
            }

            if (
                $lockedComparable->country_code === $lockedAnalysis->target_country_code
                && $lockedComparable->currency_code === $targetCurrencyCode
            ) {
                ApplicationValidation::fail(
                    'comparable',
                    ApplicationValidationCode::MarketNormalizationUnnecessary,
                );
            }

            $status = MarketCompatibilityStatus::from(
                (string) $attributes['compatibility_status'],
            );
            $observedAt = CarbonImmutable::parse(
                (string) $attributes['observed_at'],
            )->utc();
            $calculationVersion = (string) config(
                'market_normalization.calculation_version',
            );
            $facts = [
                'compatibility_status' => $status->value,
                'source_country_code' => $lockedComparable->country_code,
                'target_country_code' => $lockedAnalysis->target_country_code,
                'source_currency_code' => $lockedComparable->currency_code,
                'target_currency_code' => $targetCurrencyCode,
                'source_amount_minor' => $lockedComparable->asking_price_minor,
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
                    $lockedComparable,
                    $targetCurrencyCode,
                    $observedAt,
                    $facts,
                );
            }

            $evidence = [
                'analysis_id' => $lockedAnalysis->getKey(),
                'comparable_record_id' => $lockedComparable->getKey(),
                'comparable_evidence_hash' => $lockedComparable->evidence_hash,
                'calculation_version' => $calculationVersion,
                ...$facts,
                ...$calculated,
                'evidence_confirmed' => true,
            ];
            $encodedEvidence = json_encode(
                $evidence,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $evidenceHash = hash('sha256', $encodedEvidence);
            $normalizationKey = hash('sha256', implode('|', [
                $organization->getKey(),
                $lockedAnalysis->getKey(),
                $lockedComparable->getKey(),
                $calculationVersion,
                $evidenceHash,
            ]));
            $normalization = ComparableMarketNormalization::query()
                ->firstOrCreate(
                    ['normalization_key' => $normalizationKey],
                    [
                        'organization_id' => $organization->getKey(),
                        'analysis_id' => $lockedAnalysis->getKey(),
                        'comparable_record_id' => $lockedComparable->getKey(),
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

            return [$normalization, $match, $normalization->wasRecentlyCreated];
        }, attempts: 3);
        $set = $this->selection->refresh($analysis->fresh(), $match);

        return [
            'normalization' => $normalization->load([
                'comparableRecord',
                'exchangeRate',
                'createdBy',
            ]),
            'set' => $set,
            'created' => $created,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function calculate(
        ComparableRecord $comparable,
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

    private function targetCurrencyCode(Analysis $analysis): ?string
    {
        $value = $analysis->result_payload['normalized_listing']['currency_code']
            ?? $analysis->request_payload['listing']['currency_code']
            ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strtoupper(trim($value));
    }
}
