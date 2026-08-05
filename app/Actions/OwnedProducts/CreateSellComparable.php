<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Listings\MarketplaceConnectorType;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\OwnedProducts\OwnedProductAssessmentStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Country;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\ProductVariant;
use App\Models\SellComparableMarketNormalization;
use App\Models\SellComparableRecord;
use App\Models\SellComparableSelection;
use App\Models\SellPriceBand;
use App\Models\User;
use App\OwnedProductAssessment\CurrentOwnedProductAssessmentResolver;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CreateSellComparable
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly CurrentOwnedProductAssessmentResolver $currentAssessment,
        private readonly RefreshSellPriceIntelligence $priceIntelligence,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *   record: SellComparableRecord,
     *   selection: SellComparableSelection,
     *   price_band: SellPriceBand,
     *   created: bool
     * }
     */
    public function create(
        Organization $organization,
        User $actor,
        string $ownedProductId,
        MarketplaceSource $source,
        array $attributes,
    ): array {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $ownedProductId,
            $source,
            $attributes,
        ): array {
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

            if (
                $assessment === null
                || $assessment->getKey()
                    !== $attributes['owned_product_assessment_id']
            ) {
                ApplicationValidation::fail(
                    'owned_product_assessment_id',
                    ApplicationValidationCode::OwnedProductAssessmentStale,
                );
            }

            if (
                $assessment->status !== OwnedProductAssessmentStatus::Ready
                || $assessment->matcher_status !== ProductMatchStatus::Matched
                || $assessment->product_model_id === null
            ) {
                ApplicationValidation::fail(
                    'owned_product_assessment_id',
                    ApplicationValidationCode::OwnedProductAssessmentNotReady,
                );
            }

            if (
                ! $source->active
                || $source->connector_type !== MarketplaceConnectorType::Manual
            ) {
                ApplicationValidation::fail(
                    'marketplace_source_key',
                    ApplicationValidationCode::SellComparableManualConnectorRequired,
                );
            }

            $snapshot = $assessment->snapshot()->firstOrFail();
            $countryCode = strtoupper($attributes['country_code']);
            $currencyCode = strtoupper($attributes['currency_code']);

            if (
                ! in_array(
                    $countryCode,
                    $snapshot->target_country_codes,
                    true,
                )
            ) {
                ApplicationValidation::fail(
                    'country_code',
                    ApplicationValidationCode::SellComparableTargetCountryInvalid,
                );
            }

            $variantId = $attributes['product_variant_id'] ?? null;

            if ($variantId !== null) {
                $variantExists = ProductVariant::query()
                    ->whereKey($variantId)
                    ->where(
                        'product_model_id',
                        $assessment->product_model_id,
                    )
                    ->where('active', true)
                    ->exists();

                if (! $variantExists) {
                    ApplicationValidation::fail(
                        'product_variant_id',
                        ApplicationValidationCode::SellComparableVariantMismatch,
                    );
                }
            }

            $facts = $this->facts($attributes);
            $marketplaceKey = $this->marketplaceKey(
                $facts['marketplace_name'],
            );
            $sourceIdentityHash = $this->sourceIdentityHash(
                $organization->getKey(),
                $source->getKey(),
                $marketplaceKey,
                $facts['external_id'],
                $facts['source_url'],
            );
            $evidenceHash = hash(
                'sha256',
                json_encode(
                    [
                        ...$facts,
                        'owned_product_assessment_id' => $assessment->getKey(),
                        'assessment_input_hash' => $assessment->input_hash,
                        'product_model_id' => $assessment->product_model_id,
                        'product_variant_id' => $variantId,
                    ],
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE,
                ),
            );
            $recordKey = hash('sha256', implode('|', [
                $organization->getKey(),
                $ownedProduct->getKey(),
                $assessment->getKey(),
                $sourceIdentityHash,
                $evidenceHash,
            ]));
            $reliability = $source->reliability_score === null
                ? (int) config(
                    'sell_price_intelligence.default_source_reliability_basis_points',
                )
                : $source->reliability_score * 100;
            $record = SellComparableRecord::query()->firstOrCreate(
                ['record_key' => $recordKey],
                [
                    ...$facts,
                    'organization_id' => $organization->getKey(),
                    'owned_product_id' => $ownedProduct->getKey(),
                    'owned_product_assessment_id' => $assessment->getKey(),
                    'marketplace_source_id' => $source->getKey(),
                    'created_by_user_id' => $actor->getKey(),
                    'product_model_id' => $assessment->product_model_id,
                    'product_variant_id' => $variantId,
                    'assessment_input_hash' => $assessment->input_hash,
                    'source_identity_hash' => $sourceIdentityHash,
                    'evidence_hash' => $evidenceHash,
                    'record_key' => $recordKey,
                    'marketplace_key' => $marketplaceKey,
                    'source_reliability_basis_points' => min(
                        10000,
                        max(0, $reliability),
                    ),
                    'raw_input' => $attributes,
                ],
            );
            $created = $record->wasRecentlyCreated;
            $recordScopes = SellComparableRecord::query()
                ->where(
                    'owned_product_assessment_id',
                    $assessment->getKey(),
                )
                ->select(['country_code', 'currency_code'])
                ->distinct()
                ->orderBy('country_code')
                ->orderBy('currency_code')
                ->get();
            $defaultScopes = Country::query()
                ->whereIn('code', $snapshot->target_country_codes)
                ->where('active', true)
                ->whereNotNull('currency_code')
                ->orderBy('code')
                ->get(['code as country_code', 'currency_code']);
            $normalizationScopes = SellComparableMarketNormalization::query()
                ->where(
                    'owned_product_assessment_id',
                    $assessment->getKey(),
                )
                ->select([
                    'target_country_code as country_code',
                    'target_currency_code as currency_code',
                ])
                ->distinct()
                ->orderBy('target_country_code')
                ->orderBy('target_currency_code')
                ->get();
            $marketScopes = $defaultScopes
                ->concat($recordScopes)
                ->concat($normalizationScopes)
                ->unique(
                    static fn ($scope): string => (
                        $scope->country_code.'|'.$scope->currency_code
                    ),
                )
                ->sortBy(
                    static fn ($scope): string => (
                        $scope->country_code.'|'.$scope->currency_code
                    ),
                )
                ->values();

            if (
                $marketScopes
                    ->where('country_code', $countryCode)
                    ->count()
                > (int) config(
                    'sell_price_intelligence.max_currency_scopes_per_market',
                )
            ) {
                ApplicationValidation::fail(
                    'currency_code',
                    ApplicationValidationCode::SellComparableCurrencyScopeLimit,
                );
            }

            $selection = null;
            $priceBand = null;

            foreach ($marketScopes as $scope) {
                $scopeResult = $this->priceIntelligence->refresh(
                    $ownedProduct,
                    $assessment,
                    $scope->country_code,
                    $scope->currency_code,
                );

                if (
                    $scope->country_code === $countryCode
                    && $scope->currency_code === $currencyCode
                ) {
                    $selection = $scopeResult['selection'];
                    $priceBand = $scopeResult['price_band'];
                }
            }

            if ($selection === null || $priceBand === null) {
                throw new \LogicException(
                    'The submitted Sell comparable market scope was not recalculated.',
                );
            }

            return [
                'record' => $record->load([
                    'marketplaceSource',
                    'productVariant',
                    'createdBy',
                    'marketNormalizations',
                ]),
                'selection' => $selection,
                'price_band' => $priceBand,
                'created' => $created,
            ];
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function facts(array $attributes): array
    {
        return [
            'marketplace_name' => trim($attributes['marketplace_name']),
            'source_url' => $this->nullableTrimmed(
                $attributes['source_url'] ?? null,
            ),
            'external_id' => $this->nullableTrimmed(
                $attributes['external_id'] ?? null,
            ),
            'title' => trim($attributes['title']),
            'description' => $this->nullableTrimmed(
                $attributes['description'] ?? null,
            ),
            'listing_type' => $attributes['listing_type'],
            'condition_code' => $attributes['condition_code'],
            'seller_type' => $attributes['seller_type'],
            'asking_price_minor' => $attributes['asking_price_minor'],
            'currency_code' => strtoupper($attributes['currency_code']),
            'country_code' => strtoupper($attributes['country_code']),
            'location' => $this->nullableTrimmed(
                $attributes['location'] ?? null,
            ),
            'included_accessories' => $this->normalizedLabels(
                $attributes['included_accessories'] ?? [],
            ),
            'missing_accessories' => $this->normalizedLabels(
                $attributes['missing_accessories'] ?? [],
            ),
            'published_at' => isset($attributes['published_at'])
                ? Carbon::parse($attributes['published_at'])->utc()
                : null,
            'observed_at' => Carbon::parse($attributes['observed_at'])->utc(),
        ];
    }

    /** @return list<string> */
    private function normalizedLabels(array $values): array
    {
        return collect($values)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(static fn (string $value): string => mb_strtolower($value))
            ->sort(static fn (string $left, string $right): int => strcasecmp(
                $left,
                $right,
            ))
            ->values()
            ->all();
    }

    private function marketplaceKey(string $name): string
    {
        return str($name)->lower()->squish()->slug()->limit(80, '')->toString();
    }

    private function sourceIdentityHash(
        string $organizationId,
        string $sourceId,
        string $marketplaceKey,
        ?string $externalId,
        ?string $sourceUrl,
    ): string {
        $identity = $externalId !== null
            ? 'external:'.mb_strtolower($externalId)
            : 'url:'.mb_strtolower((string) $sourceUrl);

        return hash('sha256', implode('|', [
            $organizationId,
            $sourceId,
            $marketplaceKey,
            $identity,
        ]));
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
