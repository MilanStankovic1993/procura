<?php

namespace App\Actions\Analyses;

use App\ComparableSelection\Contracts\ComparableSelector;
use App\Enums\Comparables\ComparableSetStatus;
use App\Enums\Pricing\PriceEstimateStatus;
use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\ProductMatch;
use Illuminate\Support\Facades\DB;

class RefreshComparableSelection
{
    public function __construct(
        private readonly ComparableSelector $selector,
        private readonly RecordComparableSet $sets,
        private readonly ComparableSelectionResultProjection $projection,
        private readonly RefreshPriceEstimate $priceEstimates,
        private readonly RefreshRiskAssessment $riskAssessments,
    ) {}

    public function refresh(
        Analysis $analysis,
        ProductMatch $productMatch,
    ): ComparableSet {
        return DB::transaction(function () use (
            $analysis,
            $productMatch,
        ): ComparableSet {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedProductMatch = ProductMatch::query()
                ->lockForUpdate()
                ->findOrFail($productMatch->getKey());
            $normalizedListing = $lockedAnalysis
                ->result_payload['normalized_listing'] ?? [];
            $normalizedListing = is_array($normalizedListing)
                ? $normalizedListing
                : [];
            $selection = $this->selector->select(
                $lockedAnalysis,
                $lockedProductMatch,
                $normalizedListing,
            );
            $set = $this->sets->record(
                $lockedAnalysis->getKey(),
                $lockedProductMatch->getKey(),
                $selection,
            );
            $this->projection->apply($lockedAnalysis, $set);

            if ($set->status === ComparableSetStatus::Ready) {
                $estimate = $this->priceEstimates->refresh(
                    $lockedAnalysis->fresh(),
                    $set,
                    $normalizedListing,
                );

                if ($estimate->status !== PriceEstimateStatus::NeedsInput) {
                    $this->riskAssessments->refresh(
                        $lockedAnalysis->fresh(),
                        $lockedProductMatch,
                        $set,
                        $estimate,
                        $normalizedListing,
                    );
                }
            }

            return $set;
        }, attempts: 3);
    }
}
