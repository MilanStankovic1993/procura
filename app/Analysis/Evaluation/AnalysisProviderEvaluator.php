<?php

namespace App\Analysis\Evaluation;

use App\Analysis\Contracts\ConfiguredListingAiAnalyzer;
use App\Analysis\Contracts\ListingAiAnalyzer;
use App\Analysis\Data\AnalysisInputData;
use JsonException;
use RuntimeException;

final class AnalysisProviderEvaluator
{
    public function __construct(
        private readonly AnalysisProviderEvaluationConfiguration $configuration,
    ) {}

    /** @throws JsonException */
    public function evaluate(
        GoldenAnalysisDataset $dataset,
        ListingAiAnalyzer $analyzer,
        string $provider,
        string $model,
        string $releaseSha,
        bool $eligibleForReleaseEvidence,
    ): AnalysisProviderEvaluationReport {
        $inputs = [];
        $reservations = [];

        foreach ($dataset->cases as $case) {
            $encoded = json_encode(
                $case['input'],
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
            $inputHash = hash('sha256', $encoded);
            $inputs[] = new AnalysisInputData(
                analysisId: 'golden-evaluation-'.$inputHash,
                inputHash: $inputHash,
                requestPayload: $case['input'],
            );
        }

        foreach ($inputs as $input) {
            $reservations[] = $analyzer instanceof ConfiguredListingAiAnalyzer
                ? $analyzer->maximumCostMinor($input)
                : 0;
        }

        $maximumReservedCost = array_sum($reservations);

        if ($maximumReservedCost > $this->configuration->maximumTotalCostMinor()) {
            throw new RuntimeException(
                'The golden evaluation maximum cost exceeds its hard cap.',
            );
        }

        $counts = [
            'passed_case_count' => 0,
            'title_exact_count' => 0,
            'description_exact_count' => 0,
            'needs_input_exact_count' => 0,
            'immutable_projection_count' => 0,
            'confidence_range_count' => 0,
            'cost_reservation_count' => 0,
        ];
        $tokensIn = 0;
        $tokensOut = 0;
        $actualCost = 0;

        foreach ($dataset->cases as $index => $case) {
            $result = $analyzer->analyze($inputs[$index]);
            $expected = $case['expected'];
            $titleExact = ($result->normalizedListing['title'] ?? null)
                === $expected['title'];
            $descriptionExact = (
                $result->normalizedListing['description'] ?? null
            ) === $expected['description'];
            $needsInputExact = $result->needsInput === $expected['needs_input'];
            $confidenceInRange = $result->confidenceBasisPoints
                >= $expected['minimum_confidence_basis_points']
                && $result->confidenceBasisPoints
                    <= $expected['maximum_confidence_basis_points'];
            $immutableProjection = $this->immutableProjectionMatches(
                $result->normalizedListing,
                $case['input'],
            );
            $costWithinReservation = $result->estimatedCostCurrency === 'USD'
                && $result->estimatedCostMinor <= $reservations[$index];

            if (! $costWithinReservation) {
                throw new RuntimeException(
                    'The provider exceeded the evaluation reservation.',
                );
            }

            $counts['title_exact_count'] += (int) $titleExact;
            $counts['description_exact_count'] += (int) $descriptionExact;
            $counts['needs_input_exact_count'] += (int) $needsInputExact;
            $counts['immutable_projection_count'] += (int) $immutableProjection;
            $counts['confidence_range_count'] += (int) $confidenceInRange;
            $counts['cost_reservation_count'] += (int) $costWithinReservation;
            $counts['passed_case_count'] += (int) (
                $titleExact
                && $descriptionExact
                && $needsInputExact
                && $confidenceInRange
                && $immutableProjection
                && $costWithinReservation
            );
            $tokensIn += $result->tokensIn;
            $tokensOut += $result->tokensOut;
            $actualCost += $result->estimatedCostMinor;
        }

        $caseCount = count($dataset->cases);
        $metrics = [
            'case_count' => $caseCount,
            ...$counts,
            'failed_case_count' => $caseCount - $counts['passed_case_count'],
            'case_pass_rate_basis_points' => $this->rate(
                $counts['passed_case_count'],
                $caseCount,
            ),
            'title_exact_rate_basis_points' => $this->rate(
                $counts['title_exact_count'],
                $caseCount,
            ),
            'description_exact_rate_basis_points' => $this->rate(
                $counts['description_exact_count'],
                $caseCount,
            ),
            'needs_input_exact_rate_basis_points' => $this->rate(
                $counts['needs_input_exact_count'],
                $caseCount,
            ),
            'immutable_projection_rate_basis_points' => $this->rate(
                $counts['immutable_projection_count'],
                $caseCount,
            ),
            'confidence_range_rate_basis_points' => $this->rate(
                $counts['confidence_range_count'],
                $caseCount,
            ),
            'cost_reservation_rate_basis_points' => $this->rate(
                $counts['cost_reservation_count'],
                $caseCount,
            ),
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'maximum_reserved_cost_minor' => $maximumReservedCost,
            'actual_cost_minor' => $actualCost,
        ];
        $budget = $this->configuration->budget();
        $failedChecks = $this->failedChecks($metrics, $budget);

        return new AnalysisProviderEvaluationReport(
            contractVersion: $this->configuration->reportContractVersion(),
            environment: app()->environment(),
            releaseSha: $releaseSha,
            provider: $provider,
            model: $model,
            promptVersion: (string) config('analyses.prompt_version'),
            datasetVersion: $dataset->version,
            datasetSha256: $dataset->sha256,
            metrics: $metrics,
            budget: $budget,
            failedChecks: $failedChecks,
            eligibleForReleaseEvidence: $eligibleForReleaseEvidence,
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $input
     */
    private function immutableProjectionMatches(
        array $normalized,
        array $input,
    ): bool {
        $keys = array_keys($normalized);
        sort($keys);
        $expectedKeys = [
            'asking_price_minor',
            'currency_code',
            'description',
            'evidence_count',
            'marketplace_name',
            'source_country_code',
            'target_country_code',
            'title',
        ];

        if ($keys !== $expectedKeys) {
            return false;
        }

        return [
            'marketplace_name' => $normalized['marketplace_name'],
            'asking_price_minor' => $normalized['asking_price_minor'],
            'currency_code' => $normalized['currency_code'],
            'source_country_code' => $normalized['source_country_code'],
            'target_country_code' => $normalized['target_country_code'],
            'evidence_count' => $normalized['evidence_count'],
        ] === [
            'marketplace_name' => trim((string) $input['listing']['marketplace_name']),
            'asking_price_minor' => $input['listing']['asking_price_minor'],
            'currency_code' => $input['listing']['currency_code'],
            'source_country_code' => $input['market_scope']['source_country_code'],
            'target_country_code' => $input['market_scope']['target_country_code'],
            'evidence_count' => count($input['evidence']),
        ];
    }

    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, int|string>  $budget
     * @return list<string>
     */
    private function failedChecks(array $metrics, array $budget): array
    {
        $checks = [
            'case_pass_rate' => 'minimum_case_pass_rate_basis_points',
            'title_exact_rate' => 'minimum_title_exact_rate_basis_points',
            'description_exact_rate' => (
                'minimum_description_exact_rate_basis_points'
            ),
            'needs_input_exact_rate' => (
                'minimum_needs_input_exact_rate_basis_points'
            ),
            'immutable_projection_rate' => (
                'minimum_immutable_projection_rate_basis_points'
            ),
            'confidence_range_rate' => (
                'minimum_confidence_range_rate_basis_points'
            ),
        ];
        $failed = [];

        foreach ($checks as $metric => $budgetKey) {
            if ($metrics[$metric.'_basis_points'] < $budget[$budgetKey]) {
                $failed[] = $metric;
            }
        }

        if ($metrics['cost_reservation_rate_basis_points'] !== 10_000) {
            $failed[] = 'cost_reservation';
        }

        if ($metrics['actual_cost_minor'] > $budget['maximum_total_cost_minor']) {
            $failed[] = 'total_cost';
        }

        return $failed;
    }

    private function rate(int $passing, int $total): int
    {
        return intdiv($passing * 10_000, $total);
    }
}
