<?php

namespace App\Analysis\Providers;

use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Data\AiAnalysisData;
use App\Analysis\Data\AnalysisInputData;

class FakeListingAiAnalyzer implements ListingAiAnalyzer
{
    public function analyze(AnalysisInputData $input): AiAnalysisData
    {
        $listing = $input->requestPayload['listing'] ?? [];
        $evidence = $input->requestPayload['evidence'] ?? [];
        $needsInput = [];

        if (($listing['asking_price_minor'] ?? null) === null) {
            $needsInput[] = 'price_missing';
        }

        if (($listing['currency_code'] ?? null) === null) {
            $needsInput[] = 'currency_unclear';
        }

        if (! is_array($evidence) || $evidence === []) {
            $needsInput[] = 'images_insufficient';
        }

        return new AiAnalysisData(
            normalizedListing: [
                'title' => trim((string) ($listing['title'] ?? '')),
                'description' => isset($listing['description'])
                    ? trim((string) $listing['description'])
                    : null,
                'marketplace_name' => trim((string) ($listing['marketplace_name'] ?? '')),
                'asking_price_minor' => $listing['asking_price_minor'] ?? null,
                'currency_code' => $listing['currency_code'] ?? null,
                'source_country_code' => $input->requestPayload['market_scope']['source_country_code'] ?? null,
                'target_country_code' => $input->requestPayload['market_scope']['target_country_code'] ?? null,
                'evidence_count' => is_array($evidence) ? count($evidence) : 0,
            ],
            confidenceBasisPoints: $needsInput === [] ? 9000 : 6500,
            needsInput: $needsInput,
            warnings: [
                'Deterministic extraction provider output; downstream stages must use independently recorded evidence.',
            ],
        );
    }
}
