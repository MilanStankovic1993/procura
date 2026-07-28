<?php

namespace App\RiskAssessment\Evaluators;

use App\Enums\Pricing\PriceConfidenceLevel;
use App\Enums\Pricing\PriceEstimateStatus;
use App\Enums\Risk\RiskAssessmentStatus;
use App\Enums\Risk\RiskCategory;
use App\Enums\Risk\RiskConfidenceLevel;
use App\Enums\Risk\RiskLevel;
use App\Enums\Risk\RiskSeverity;
use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\PriceEstimate;
use App\Models\ProductMatch;
use App\RiskAssessment\Contracts\RiskEvaluator;
use App\RiskAssessment\Data\RiskAssessmentData;
use App\RiskAssessment\Data\RiskSignalData;
use Carbon\CarbonImmutable;
use LogicException;

class DeterministicRiskEvaluator implements RiskEvaluator
{
    public function evaluate(
        Analysis $analysis,
        ProductMatch $productMatch,
        ComparableSet $comparableSet,
        PriceEstimate $priceEstimate,
        array $targetFacts = [],
    ): RiskAssessmentData {
        $this->guardOwnership(
            $analysis,
            $productMatch,
            $comparableSet,
            $priceEstimate,
        );

        if (
            $priceEstimate->status === PriceEstimateStatus::NeedsInput
            || $priceEstimate->estimate_low_minor === null
            || $priceEstimate->estimate_minor === null
        ) {
            throw new LogicException(
                'Risk assessment requires a completed price estimate.',
            );
        }

        $requestListing = $analysis->request_payload['listing'] ?? [];
        $requestEvidence = $analysis->request_payload['evidence'] ?? [];
        $listing = is_array($requestListing) ? $requestListing : [];
        $evidence = is_array($requestEvidence) ? $requestEvidence : [];
        $askingPrice = $this->nullableInteger(
            $listing['asking_price_minor']
                ?? $targetFacts['asking_price_minor']
                ?? null,
        );
        $askingCurrency = strtoupper((string) (
            $listing['currency_code']
                ?? $targetFacts['currency_code']
                ?? ''
        ));
        $sellerInformation = trim((string) (
            $listing['seller_information'] ?? ''
        ));
        $location = trim((string) ($listing['location'] ?? ''));
        $sourceCountry = strtoupper($analysis->source_country_code);
        $targetCountry = strtoupper($analysis->target_country_code);
        $inputSnapshot = [
            'analysis' => [
                'id' => $analysis->getKey(),
                'request_hash' => $analysis->request_hash,
                'source_country_code' => $sourceCountry,
                'target_country_code' => $targetCountry,
            ],
            'listing' => [
                'id' => $listing['id'] ?? null,
                'snapshot_id' => $listing['snapshot_id'] ?? null,
                'asking_price_minor' => $askingPrice,
                'currency_code' => $askingCurrency ?: null,
                'seller_information' => $sellerInformation ?: null,
                'location' => $location ?: null,
                'source_url' => $listing['source_url'] ?? null,
                'external_id' => $listing['external_id'] ?? null,
                'evidence_count' => count($evidence),
            ],
            'product_match' => [
                'id' => $productMatch->getKey(),
                'input_hash' => $productMatch->input_hash,
                'product_model_id' => $productMatch->product_model_id,
                'product_variant_id' => $productMatch->product_variant_id,
                'confidence_basis_points' => $productMatch->confidence_basis_points,
            ],
            'comparable_set' => [
                'id' => $comparableSet->getKey(),
                'input_hash' => $comparableSet->input_hash,
                'included_count' => $comparableSet->included_count,
            ],
            'price_estimate' => [
                'id' => $priceEstimate->getKey(),
                'input_hash' => $priceEstimate->input_hash,
                'status' => $priceEstimate->status->value,
                'target_currency_code' => $priceEstimate->target_currency_code,
                'estimate_low_minor' => $priceEstimate->estimate_low_minor,
                'estimate_minor' => $priceEstimate->estimate_minor,
                'estimate_high_minor' => $priceEstimate->estimate_high_minor,
                'confidence_basis_points' => $priceEstimate->confidence_basis_points,
                'confidence_level' => $priceEstimate->confidence_level?->value,
                'reason_codes' => $priceEstimate->reason_codes,
            ],
        ];
        $inputHash = hash(
            'sha256',
            json_encode(
                $inputSnapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );
        $signals = [];

        if (
            $askingPrice !== null
            && $askingPrice > 0
            && $askingCurrency === $priceEstimate->target_currency_code
        ) {
            $lowerBand = $priceEstimate->estimate_low_minor;

            if ($askingPrice < $lowerBand) {
                $deviation = intdiv(
                    (($lowerBand - $askingPrice) * 10000) + intdiv($lowerBand, 2),
                    $lowerBand,
                );
                [$points, $severity] = match (true) {
                    $deviation >= 5000 => [30, RiskSeverity::High],
                    $deviation >= 3000 => [20, RiskSeverity::High],
                    $deviation >= 1500 => [10, RiskSeverity::Medium],
                    default => [5, RiskSeverity::Low],
                };
                $signals[] = $this->signal(
                    code: 'asking_price_below_observed_band',
                    category: RiskCategory::Listing,
                    severity: $severity,
                    points: $points,
                    evidence: [
                        'asking_price_minor' => $askingPrice,
                        'observed_lower_band_minor' => $lowerBand,
                        'observed_center_minor' => $priceEstimate->estimate_minor,
                        'currency_code' => $askingCurrency,
                        'deviation_basis_points' => $deviation,
                    ],
                    source: 'listing_snapshot+price_estimate',
                    confidence: min(
                        $productMatch->confidence_basis_points,
                        $priceEstimate->confidence_basis_points ?? 0,
                    ),
                    action: 'Verify the listing, seller, product identity, and condition before payment.',
                );
            }
        } else {
            $signals[] = $this->unknown(
                code: 'asking_price_comparison_unavailable',
                category: RiskCategory::Listing,
                evidence: [
                    'asking_price_minor' => $askingPrice,
                    'asking_currency_code' => $askingCurrency ?: null,
                    'estimate_currency_code' => $priceEstimate->target_currency_code,
                ],
                source: 'listing_snapshot+price_estimate',
                action: 'Record a positive asking price in the estimate currency or provide verified conversion evidence.',
            );
        }

        if (
            $priceEstimate->status === PriceEstimateStatus::LowConfidence
            || $priceEstimate->confidence_level === PriceConfidenceLevel::Low
        ) {
            $signals[] = $this->signal(
                code: 'price_evidence_low_confidence',
                category: RiskCategory::Listing,
                severity: RiskSeverity::Medium,
                points: 10,
                evidence: [
                    'confidence_basis_points' => $priceEstimate->confidence_basis_points,
                    'dispersion_basis_points' => $priceEstimate->dispersion_basis_points,
                    'included_count' => $priceEstimate->included_count,
                    'reason_codes' => $priceEstimate->reason_codes,
                ],
                source: 'price_estimate',
                confidence: $priceEstimate->confidence_basis_points ?? 0,
                action: 'Collect additional verified comparables before relying on the market band.',
            );
        }

        if ($sourceCountry !== $targetCountry) {
            $signals[] = $this->signal(
                code: 'cross_border_transaction_context',
                category: RiskCategory::Transaction,
                severity: RiskSeverity::Medium,
                points: 10,
                evidence: [
                    'source_country_code' => $sourceCountry,
                    'target_country_code' => $targetCountry,
                ],
                source: 'analysis_market_scope',
                confidence: 10000,
                action: 'Verify shipping, customs, tax, returns, and regional compatibility before purchase.',
            );
        }

        if ($sellerInformation === '') {
            $signals[] = $this->unknown(
                code: 'seller_information_not_recorded',
                category: RiskCategory::Seller,
                evidence: ['seller_information' => null],
                source: 'listing_snapshot',
                action: 'Verify seller identity, history, ownership, and contact details.',
            );
        }

        if ($location === '') {
            $signals[] = $this->unknown(
                code: 'listing_location_not_recorded',
                category: RiskCategory::Seller,
                evidence: ['location' => null],
                source: 'listing_snapshot',
                action: 'Confirm the physical location of the product and seller.',
            );
        }

        if ($evidence === []) {
            $signals[] = $this->unknown(
                code: 'product_images_not_recorded',
                category: RiskCategory::Product,
                evidence: ['evidence_count' => 0],
                source: 'analysis_request_evidence',
                action: 'Obtain current, original product and serial-number images.',
            );
        }

        $signals[] = $this->unknown(
            code: 'condition_not_verified',
            category: RiskCategory::Product,
            evidence: ['structured_condition' => null],
            source: 'analysis_request_contract',
            action: 'Inspect and document condition, defects, included parts, and functionality.',
        );
        $signals[] = $this->unknown(
            code: 'ownership_and_serial_not_verified',
            category: RiskCategory::Product,
            evidence: [
                'ownership_proof' => null,
                'serial_number_verification' => null,
            ],
            source: 'analysis_request_contract',
            action: 'Verify ownership proof, serial number, locks, and blacklist status.',
        );
        $signals[] = $this->unknown(
            code: 'payment_protection_not_verified',
            category: RiskCategory::Transaction,
            evidence: ['payment_protection' => null],
            source: 'analysis_request_contract',
            action: 'Use a traceable payment method with buyer protection.',
        );
        $signals[] = $this->unknown(
            code: 'shipping_and_returns_not_verified',
            category: RiskCategory::Transaction,
            evidence: [
                'shipping_terms' => null,
                'return_terms' => null,
            ],
            source: 'analysis_request_contract',
            action: 'Confirm insured shipping, handover evidence, and return terms.',
        );

        if (count($signals) > (int) config('risk_assessment.max_signals')) {
            throw new LogicException(
                'Risk evaluation exceeded its configured hard bound.',
            );
        }

        $confidenceComponents = [
            'price_evidence' => $this->weightedConfidence(
                $priceEstimate->confidence_basis_points ?? 0,
                3500,
            ),
            'product_identity' => $this->weightedConfidence(
                $productMatch->confidence_basis_points,
                2000,
            ),
            'source_evidence' => $evidence === [] ? 0 : 1000,
            'seller_context' => $sellerInformation === '' ? 0 : 750,
            'location_context' => $location === '' ? 0 : 500,
            'condition_verification' => 0,
            'ownership_verification' => 0,
            'transaction_scope' => $sourceCountry === $targetCountry ? 500 : 0,
            'payment_protection' => 0,
        ];
        $score = min(100, array_sum(array_map(
            static fn (RiskSignalData $signal): int => $signal->scoreContribution,
            $signals,
        )));
        $confidence = min(10000, array_sum($confidenceComponents));
        $unknownCount = count(array_filter(
            $signals,
            static fn (RiskSignalData $signal): bool => $signal->isUnknown,
        ));
        $reasonCodes = array_values(array_unique([
            ...array_map(
                static fn (RiskSignalData $signal): string => $signal->code,
                $signals,
            ),
            ...($unknownCount > 0 ? ['risk_assessment_has_unknowns'] : []),
            ...($score === 0 ? ['no_scored_risk_signal_recorded'] : []),
        ]));
        $verificationActions = array_values(array_unique(array_filter(
            array_map(
                static fn (RiskSignalData $signal): ?string => $signal->verificationAction,
                $signals,
            ),
        )));

        return new RiskAssessmentData(
            status: RiskAssessmentStatus::Assessed,
            evaluatorVersion: (string) config('risk_assessment.evaluator_version'),
            inputHash: $inputHash,
            calculationAt: CarbonImmutable::now()->utc(),
            score: $score,
            level: RiskLevel::fromScore($score),
            confidenceBasisPoints: $confidence,
            confidenceLevel: RiskConfidenceLevel::fromBasisPoints($confidence),
            reasonCodes: $reasonCodes,
            confidenceComponents: $confidenceComponents,
            verificationActions: $verificationActions,
            inputSnapshot: $inputSnapshot,
            signals: $signals,
        );
    }

    private function guardOwnership(
        Analysis $analysis,
        ProductMatch $productMatch,
        ComparableSet $comparableSet,
        PriceEstimate $priceEstimate,
    ): void {
        if (
            $productMatch->analysis_id !== $analysis->getKey()
            || $comparableSet->analysis_id !== $analysis->getKey()
            || $comparableSet->product_match_id !== $productMatch->getKey()
            || $priceEstimate->analysis_id !== $analysis->getKey()
            || $priceEstimate->comparable_set_id !== $comparableSet->getKey()
            || $productMatch->organization_id !== $analysis->organization_id
            || $comparableSet->organization_id !== $analysis->organization_id
            || $priceEstimate->organization_id !== $analysis->organization_id
        ) {
            throw new LogicException(
                'Risk inputs do not belong to one immutable analysis evidence chain.',
            );
        }
    }

    /** @param array<string, mixed> $evidence */
    private function signal(
        string $code,
        RiskCategory $category,
        RiskSeverity $severity,
        int $points,
        array $evidence,
        string $source,
        int $confidence,
        string $action,
    ): RiskSignalData {
        return new RiskSignalData(
            code: $code,
            category: $category,
            severity: $severity,
            isUnknown: false,
            weightPoints: $points,
            scoreContribution: $points,
            evidence: $evidence,
            source: $source,
            confidenceBasisPoints: max(0, min(10000, $confidence)),
            verificationAction: $action,
        );
    }

    /** @param array<string, mixed> $evidence */
    private function unknown(
        string $code,
        RiskCategory $category,
        array $evidence,
        string $source,
        string $action,
    ): RiskSignalData {
        return new RiskSignalData(
            code: $code,
            category: $category,
            severity: RiskSeverity::Low,
            isUnknown: true,
            weightPoints: 0,
            scoreContribution: 0,
            evidence: $evidence,
            source: $source,
            confidenceBasisPoints: 0,
            verificationAction: $action,
        );
    }

    private function weightedConfidence(int $basisPoints, int $maximum): int
    {
        return intdiv(($basisPoints * $maximum) + 5000, 10000);
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : null;
    }
}
