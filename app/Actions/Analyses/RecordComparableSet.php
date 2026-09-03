<?php

namespace App\Actions\Analyses;

use App\ComparableSelection\Data\ComparableSelectionData;
use App\Enums\Comparables\ComparableDecision;
use App\Models\Analysis;
use App\Models\ComparableSet;
use App\Models\ProductMatch;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordComparableSet
{
    public function record(
        string $analysisId,
        string $productMatchId,
        ComparableSelectionData $result,
    ): ComparableSet {
        return DB::transaction(function () use (
            $analysisId,
            $productMatchId,
            $result,
        ): ComparableSet {
            $analysis = Analysis::query()->lockForUpdate()->findOrFail($analysisId);
            $productMatch = ProductMatch::query()
                ->lockForUpdate()
                ->findOrFail($productMatchId);

            if (
                $productMatch->analysis_id !== $analysis->getKey()
                || $productMatch->organization_id !== $analysis->organization_id
            ) {
                throw new LogicException(
                    'The product match does not belong to the requested comparable set.',
                );
            }

            $selectionKey = hash('sha256', implode('|', [
                $analysis->getKey(),
                $productMatch->getKey(),
                $result->selectorVersion,
                $result->inputHash,
            ]));
            $existing = ComparableSet::query()
                ->where('selection_key', $selectionKey)
                ->first();

            if ($existing !== null) {
                return $existing->load('items.comparableRecord.marketplaceSource');
            }

            $runNumber = ((int) ComparableSet::query()
                ->where('analysis_id', $analysis->getKey())
                ->max('run_number')) + 1;
            $set = ComparableSet::query()->create([
                'organization_id' => $analysis->organization_id,
                'analysis_id' => $analysis->getKey(),
                'product_match_id' => $productMatch->getKey(),
                'run_number' => $runNumber,
                'status' => $result->status,
                'selector_version' => $result->selectorVersion,
                'input_hash' => $result->inputHash,
                'selection_key' => $selectionKey,
                'target_country_code' => $result->targetCountryCode,
                'target_currency_code' => $result->targetCurrencyCode,
                'candidate_count' => $result->candidateCount,
                'included_count' => count($result->included),
                'excluded_count' => count($result->excluded),
                'minimum_required' => $result->minimumRequired,
                'reason_codes' => $result->reasonCodes,
            ]);
            $items = [];

            foreach ($result->included as $index => $item) {
                $items[] = [
                    ...$item,
                    'decision' => ComparableDecision::Included,
                    'rank' => $index + 1,
                ];
            }

            foreach ($result->excluded as $item) {
                $items[] = [
                    ...$item,
                    'decision' => ComparableDecision::Excluded,
                    'rank' => null,
                ];
            }

            $set->items()->createMany($items);

            return $set->load('items.comparableRecord.marketplaceSource');
        }, attempts: 3);
    }
}
