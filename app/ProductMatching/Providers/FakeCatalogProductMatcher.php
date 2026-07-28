<?php

namespace App\ProductMatching\Providers;

use App\Catalog\CatalogTextNormalizer;
use App\Enums\Catalog\ProductMatchReviewStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Models\ProductAlias;
use App\ProductMatching\Contracts\ProductMatcher;
use App\ProductMatching\Data\ProductMatchData;
use App\ProductMatching\Data\ProductMatchingInputData;
use Illuminate\Support\Collection;

class FakeCatalogProductMatcher implements ProductMatcher
{
    public function match(ProductMatchingInputData $input): ProductMatchData
    {
        $normalizedText = CatalogTextNormalizer::normalize($input->searchableText());
        $normalizedTitle = CatalogTextNormalizer::normalize($input->title);
        $terms = CatalogTextNormalizer::ngrams(
            $input->searchableText(),
            (int) config('product_matching.max_text_tokens'),
            (int) config('product_matching.max_ngram_tokens'),
            (int) config('product_matching.max_alias_query_terms'),
        );
        $matchInputHash = hash('sha256', implode('|', [
            $input->inputHash,
            $normalizedText,
            ...$input->marketCountryCodes,
            $input->targetCountryCode,
            config('product_matching.matcher_version'),
        ]));

        if ($terms === []) {
            return $this->unmatched($matchInputHash, 'searchable_product_text_missing');
        }

        $aliases = ProductAlias::query()
            ->where('active', true)
            ->whereIn('normalized_alias', $terms)
            ->where(static function ($query) use ($input): void {
                $query->whereNull('country_code')
                    ->orWhereIn('country_code', $input->marketCountryCodes);
            })
            ->whereHas('productModel', static function ($query): void {
                $query->where('active', true)
                    ->whereHas('brand', static fn ($query) => $query->where('active', true))
                    ->whereHas('category', static fn ($query) => $query->where('active', true));
            })
            ->where(static function ($query): void {
                $query->whereNull('product_variant_id')
                    ->orWhereHas(
                        'productVariant',
                        static fn ($query) => $query->where('active', true),
                    );
            })
            ->with([
                'productModel.brand',
                'productModel.category',
                'productVariant.marketContexts',
            ])
            ->orderByRaw('LENGTH(normalized_alias) DESC')
            ->limit(max(25, (int) config('product_matching.max_candidates') * 10))
            ->get();

        $candidates = $this->buildCandidates(
            $aliases,
            $normalizedText,
            $normalizedTitle,
            $input->targetCountryCode,
        );

        if ($candidates === []) {
            return $this->unmatched($matchInputHash, 'catalog_alias_not_found');
        }

        $maximumCandidates = max(1, (int) config('product_matching.max_candidates'));
        $candidates = array_slice($candidates, 0, $maximumCandidates);
        $top = $candidates[0];
        $second = $candidates[1] ?? null;
        $scoreDelta = (int) config('product_matching.review_score_delta');
        $ambiguous = $second !== null && ($top['score'] - $second['score']) <= $scoreDelta;
        $lowScore = $top['score'] < (int) config(
            'product_matching.automatic_match_minimum_score',
        );
        $regionIncompatible = $top['region_compatibility'] === 'incompatible';
        $confidence = $this->confidence((int) $top['score']);

        if ($ambiguous) {
            return new ProductMatchData(
                status: ProductMatchStatus::ReviewRequired,
                reviewStatus: ProductMatchReviewStatus::Pending,
                method: 'ambiguous_exact_alias',
                matcherVersion: config('product_matching.matcher_version'),
                inputHash: $matchInputHash,
                confidenceBasisPoints: min($confidence, 6500),
                productModelId: null,
                productVariantId: null,
                candidates: $candidates,
                reasonCodes: ['multiple_close_catalog_candidates'],
            );
        }

        if ($regionIncompatible || $lowScore) {
            return new ProductMatchData(
                status: ProductMatchStatus::ReviewRequired,
                reviewStatus: ProductMatchReviewStatus::Pending,
                method: $regionIncompatible
                    ? 'exact_alias_region_incompatible'
                    : 'low_confidence_exact_alias',
                matcherVersion: config('product_matching.matcher_version'),
                inputHash: $matchInputHash,
                confidenceBasisPoints: min($confidence, 7900),
                productModelId: $top['product_model_id'],
                productVariantId: $top['product_variant_id'],
                candidates: $candidates,
                reasonCodes: [
                    $regionIncompatible
                        ? 'target_market_variant_incompatible'
                        : 'catalog_match_below_auto_threshold',
                ],
            );
        }

        return new ProductMatchData(
            status: ProductMatchStatus::Matched,
            reviewStatus: ProductMatchReviewStatus::NotRequired,
            method: 'exact_catalog_alias',
            matcherVersion: config('product_matching.matcher_version'),
            inputHash: $matchInputHash,
            confidenceBasisPoints: $confidence,
            productModelId: $top['product_model_id'],
            productVariantId: $top['product_variant_id'],
            candidates: $candidates,
            reasonCodes: ['exact_catalog_alias'],
        );
    }

    /**
     * @param  Collection<int, ProductAlias>  $aliases
     * @return list<array<string, mixed>>
     */
    private function buildCandidates(
        Collection $aliases,
        string $normalizedText,
        string $normalizedTitle,
        string $targetCountryCode,
    ): array {
        $candidates = [];

        foreach ($aliases as $alias) {
            $model = $alias->productModel;
            $variant = $alias->productVariant;
            $brandPresent = str_contains(
                " {$normalizedText} ",
                " {$model->brand->normalized_name} ",
            );
            $aliasTokenCount = count(explode(' ', $alias->normalized_alias));
            $marketContexts = $variant?->marketContexts ?? collect();
            $regionCompatibility = $variant === null || $marketContexts->isEmpty()
                ? 'unspecified'
                : (
                    $marketContexts->contains('country_code', $targetCountryCode)
                        ? 'compatible'
                        : 'incompatible'
                );
            $score = ($aliasTokenCount * 1200)
                + ($brandPresent ? 1500 : 0)
                + ($normalizedTitle === $alias->normalized_alias ? 1500 : 0)
                + ($alias->country_code === $targetCountryCode ? 300 : 0)
                + match ($regionCompatibility) {
                    'compatible' => 500,
                    'incompatible' => -3000,
                    default => 0,
                };
            $candidateKey = $model->getKey().':'.($variant?->getKey() ?? 'model');
            $candidate = [
                'product_model_id' => $model->getKey(),
                'product_variant_id' => $variant?->getKey(),
                'brand' => $model->brand->name,
                'model' => $model->name,
                'model_number' => $model->model_number,
                'variant' => $variant?->name,
                'category' => $model->category->name,
                'matched_alias' => $alias->alias,
                'alias_scope_country_code' => $alias->country_code,
                'region_compatibility' => $regionCompatibility,
                'score' => max(0, $score),
            ];

            if (
                ! isset($candidates[$candidateKey])
                || $candidate['score'] > $candidates[$candidateKey]['score']
            ) {
                $candidates[$candidateKey] = $candidate;
            }
        }

        $candidates = array_values($candidates);
        usort($candidates, static function (array $left, array $right): int {
            return [
                $right['score'],
                $right['product_model_id'],
                $right['product_variant_id'] ?? '',
            ] <=> [
                $left['score'],
                $left['product_model_id'],
                $left['product_variant_id'] ?? '',
            ];
        });

        return $candidates;
    }

    private function unmatched(string $inputHash, string $reason): ProductMatchData
    {
        return new ProductMatchData(
            status: ProductMatchStatus::Unmatched,
            reviewStatus: ProductMatchReviewStatus::Pending,
            method: 'no_catalog_alias',
            matcherVersion: config('product_matching.matcher_version'),
            inputHash: $inputHash,
            confidenceBasisPoints: 0,
            productModelId: null,
            productVariantId: null,
            candidates: [],
            reasonCodes: [$reason],
        );
    }

    private function confidence(int $score): int
    {
        return min(9800, max(0, 5500 + (int) round($score * 0.65)));
    }
}
