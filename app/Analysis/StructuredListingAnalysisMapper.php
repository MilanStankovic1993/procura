<?php

namespace App\Analysis;

use App\Analysis\Data\AiAnalysisData;
use App\Analysis\Data\AnalysisInputData;
use App\Exceptions\AnalysisProviderException;

final class StructuredListingAnalysisMapper
{
    /** @param array<string, mixed> $result */
    public function map(
        array $result,
        AnalysisInputData $input,
        int $tokensIn,
        int $tokensOut,
        int $estimatedCostMinor,
    ): AiAnalysisData {
        $keys = array_keys($result);
        sort($keys);

        if ($keys !== [
            'confidence_basis_points',
            'normalized_description',
            'normalized_title',
        ]) {
            $this->invalid();
        }

        $title = $result['normalized_title'] ?? null;
        $description = $result['normalized_description'] ?? null;
        $confidence = $result['confidence_basis_points'] ?? null;

        if (
            ! is_string($title)
            || trim($title) === ''
            || mb_strlen($title) > 500
            || (! is_string($description) && $description !== null)
            || (is_string($description) && mb_strlen($description) > 10000)
            || ! is_int($confidence)
            || $confidence < 0
            || $confidence > 10000
            || $tokensIn < 0
            || $tokensOut < 0
            || $estimatedCostMinor < 0
        ) {
            $this->invalid();
        }

        $listing = is_array($input->requestPayload['listing'] ?? null)
            ? $input->requestPayload['listing']
            : [];
        $marketScope = is_array($input->requestPayload['market_scope'] ?? null)
            ? $input->requestPayload['market_scope']
            : [];
        $evidence = is_array($input->requestPayload['evidence'] ?? null)
            ? $input->requestPayload['evidence']
            : [];
        $needsInput = [];

        if (($listing['asking_price_minor'] ?? null) === null) {
            $needsInput[] = 'price_missing';
        }

        if (($listing['currency_code'] ?? null) === null) {
            $needsInput[] = 'currency_unclear';
        }

        if ($evidence === []) {
            $needsInput[] = 'images_insufficient';
        }

        return new AiAnalysisData(
            normalizedListing: [
                'title' => trim($title),
                'description' => $description === null ? null : trim($description),
                'marketplace_name' => trim((string) ($listing['marketplace_name'] ?? '')),
                'asking_price_minor' => $listing['asking_price_minor'] ?? null,
                'currency_code' => $listing['currency_code'] ?? null,
                'source_country_code' => $marketScope['source_country_code'] ?? null,
                'target_country_code' => $marketScope['target_country_code'] ?? null,
                'evidence_count' => count($evidence),
            ],
            confidenceBasisPoints: $confidence,
            needsInput: $needsInput,
            warnings: [
                'AI-assisted normalization must be verified against the original listing evidence.',
            ],
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            estimatedCostMinor: $estimatedCostMinor,
            estimatedCostCurrency: 'USD',
        );
    }

    private function invalid(): never
    {
        throw new AnalysisProviderException(
            'analysis_provider_response_invalid',
        );
    }
}
