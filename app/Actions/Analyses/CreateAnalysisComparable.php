<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Listings\MarketplaceConnectorType;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Analysis;
use App\Models\ComparableRecord;
use App\Models\ComparableSet;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\ProductMatch;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateAnalysisComparable
{
    public function __construct(
        private readonly AnalysisAuthorizer $authorizer,
        private readonly RefreshComparableSelection $selection,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{record: ComparableRecord, set: ComparableSet, created: bool}
     */
    public function create(
        Organization $organization,
        User $actor,
        Analysis $analysis,
        MarketplaceSource $source,
        array $attributes,
    ): array {
        [$record, $match, $created] = DB::transaction(function () use (
            $organization,
            $actor,
            $analysis,
            $source,
            $attributes,
        ): array {
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
                    ApplicationValidationCode::AnalysisComparableProcessingNotFinished,
                );
            }

            if (
                $match === null
                || $match->status !== ProductMatchStatus::Matched
                || $match->product_model_id === null
            ) {
                ApplicationValidation::fail(
                    'analysis',
                    ApplicationValidationCode::AnalysisComparableProductMatchRequired,
                );
            }

            if (
                ! $source->active
                || $source->connector_type !== MarketplaceConnectorType::Manual
            ) {
                ApplicationValidation::fail(
                    'marketplace_source_key',
                    ApplicationValidationCode::AnalysisComparableManualConnectorRequired,
                );
            }

            $variantId = $attributes['product_variant_id'] ?? null;

            if ($variantId !== null) {
                $variantExists = ProductVariant::query()
                    ->whereKey($variantId)
                    ->where('product_model_id', $match->product_model_id)
                    ->where('active', true)
                    ->exists();

                if (! $variantExists) {
                    ApplicationValidation::fail(
                        'product_variant_id',
                        ApplicationValidationCode::AnalysisComparableVariantMismatch,
                    );
                }
            }

            $facts = $this->facts($attributes);
            $marketplaceKey = CreateListingComparableIdentity::marketplaceKey(
                $facts['marketplace_name'],
            );
            $sourceIdentityHash = CreateListingComparableIdentity::sourceIdentityHash(
                $organization->getKey(),
                $source->getKey(),
                $marketplaceKey,
                $facts['external_id'],
                $facts['source_url'],
            );
            $encodedFacts = json_encode(
                [
                    ...$facts,
                    'product_model_id' => $match->product_model_id,
                    'product_variant_id' => $variantId,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $evidenceHash = hash('sha256', $encodedFacts);
            $recordKey = hash('sha256', implode('|', [
                $organization->getKey(),
                $sourceIdentityHash,
                $evidenceHash,
            ]));
            $reliability = $source->reliability_score === null
                ? (int) config(
                    'comparable_selection.default_source_reliability_basis_points',
                )
                : $source->reliability_score * 100;
            $reliability = min(10000, max(0, $reliability));
            $record = ComparableRecord::query()->firstOrCreate(
                ['record_key' => $recordKey],
                [
                    ...$facts,
                    'organization_id' => $organization->getKey(),
                    'marketplace_source_id' => $source->getKey(),
                    'created_by_user_id' => $actor->getKey(),
                    'product_model_id' => $match->product_model_id,
                    'product_variant_id' => $variantId,
                    'source_identity_hash' => $sourceIdentityHash,
                    'evidence_hash' => $evidenceHash,
                    'marketplace_key' => $marketplaceKey,
                    'source_reliability_basis_points' => $reliability,
                    'raw_input' => $attributes,
                ],
            );
            $created = $record->wasRecentlyCreated;

            return [$record, $match, $created];
        }, attempts: 3);
        $set = $this->selection->refresh($analysis->fresh(), $match);

        return [
            'record' => $record->load(['marketplaceSource', 'productVariant']),
            'set' => $set,
            'created' => $created,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function facts(array $attributes): array
    {
        return [
            'marketplace_name' => trim($attributes['marketplace_name']),
            'source_url' => $this->nullableTrimmed($attributes['source_url'] ?? null),
            'external_id' => $this->nullableTrimmed($attributes['external_id'] ?? null),
            'title' => trim($attributes['title']),
            'description' => $this->nullableTrimmed($attributes['description'] ?? null),
            'listing_type' => $attributes['listing_type'],
            'condition_code' => $attributes['condition_code'],
            'seller_type' => $attributes['seller_type'],
            'asking_price_minor' => $attributes['asking_price_minor'],
            'currency_code' => strtoupper($attributes['currency_code']),
            'country_code' => strtoupper($attributes['country_code']),
            'location' => $this->nullableTrimmed($attributes['location'] ?? null),
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
            ->sort(static fn (string $left, string $right): int => strcasecmp($left, $right))
            ->values()
            ->all();
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
