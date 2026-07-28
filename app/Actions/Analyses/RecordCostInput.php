<?php

namespace App\Actions\Analyses;

use App\Enums\Profit\CostCategory;
use App\Models\Analysis;
use App\Models\CostInput;
use App\Models\PriceEstimate;
use App\Models\RiskAssessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordCostInput
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{input: CostInput, created: bool}
     */
    public function record(
        Analysis $analysis,
        PriceEstimate $priceEstimate,
        RiskAssessment $riskAssessment,
        User $actor,
        array $attributes,
    ): array {
        return DB::transaction(function () use (
            $analysis,
            $priceEstimate,
            $riskAssessment,
            $actor,
            $attributes,
        ): array {
            $lockedAnalysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($analysis->getKey());
            $lockedPriceEstimate = PriceEstimate::query()
                ->lockForUpdate()
                ->findOrFail($priceEstimate->getKey());
            $lockedRiskAssessment = RiskAssessment::query()
                ->lockForUpdate()
                ->findOrFail($riskAssessment->getKey());

            if (
                $lockedPriceEstimate->analysis_id !== $lockedAnalysis->getKey()
                || $lockedRiskAssessment->analysis_id !== $lockedAnalysis->getKey()
                || $lockedRiskAssessment->price_estimate_id
                    !== $lockedPriceEstimate->getKey()
                || $lockedPriceEstimate->organization_id
                    !== $lockedAnalysis->organization_id
                || $lockedRiskAssessment->organization_id
                    !== $lockedAnalysis->organization_id
            ) {
                throw new LogicException(
                    'Cost inputs require one immutable price and risk evidence chain.',
                );
            }

            $items = array_map(
                static function (CostCategory $category) use ($attributes): array {
                    $amount = $attributes[$category->inputKey()] ?? null;
                    $isKnown = is_int($amount);

                    return [
                        'position' => $category->position(),
                        'category' => $category->value,
                        'amount_minor' => $isKnown ? $amount : null,
                        'is_known' => $isKnown,
                        'source' => $isKnown
                            ? 'user_confirmed'
                            : 'user_unprovided',
                    ];
                },
                CostCategory::cases(),
            );
            $inputSnapshot = [
                'analysis' => [
                    'id' => $lockedAnalysis->getKey(),
                    'request_hash' => $lockedAnalysis->request_hash,
                    'source_country_code' => $lockedAnalysis->source_country_code,
                    'target_country_code' => $lockedAnalysis->target_country_code,
                ],
                'price_estimate' => [
                    'id' => $lockedPriceEstimate->getKey(),
                    'input_hash' => $lockedPriceEstimate->input_hash,
                    'currency_code' => $lockedPriceEstimate->target_currency_code,
                ],
                'risk_assessment' => [
                    'id' => $lockedRiskAssessment->getKey(),
                    'input_hash' => $lockedRiskAssessment->input_hash,
                ],
                'input_version' => config('profit_calculation.input_version'),
                'currency_code' => strtoupper((string) $attributes['currency_code']),
                'regional_compatibility_confirmed' => (
                    $attributes['regional_compatibility_confirmed'] ?? null
                ),
                'items' => array_map(
                    static fn (array $item): array => [
                        'position' => $item['position'],
                        'category' => $item['category'],
                        'amount_minor' => $item['amount_minor'],
                        'is_known' => $item['is_known'],
                    ],
                    $items,
                ),
            ];
            $inputHash = hash(
                'sha256',
                json_encode(
                    $inputSnapshot,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE,
                ),
            );
            $latest = CostInput::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->where('price_estimate_id', $lockedPriceEstimate->getKey())
                ->where('risk_assessment_id', $lockedRiskAssessment->getKey())
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->first();

            if ($latest?->input_hash === $inputHash) {
                return [
                    'input' => $latest->load('items'),
                    'created' => false,
                ];
            }

            $runNumber = ((int) CostInput::query()
                ->where('analysis_id', $lockedAnalysis->getKey())
                ->max('run_number')) + 1;
            $inputKey = hash('sha256', implode('|', [
                $lockedAnalysis->getKey(),
                $lockedPriceEstimate->getKey(),
                $lockedRiskAssessment->getKey(),
                (string) config('profit_calculation.input_version'),
                $inputHash,
                (string) $runNumber,
            ]));
            $knownCount = count(array_filter(
                $items,
                static fn (array $item): bool => $item['is_known'],
            ));
            $input = CostInput::query()->create([
                'organization_id' => $lockedAnalysis->organization_id,
                'analysis_id' => $lockedAnalysis->getKey(),
                'price_estimate_id' => $lockedPriceEstimate->getKey(),
                'risk_assessment_id' => $lockedRiskAssessment->getKey(),
                'submitted_by_user_id' => $actor->getKey(),
                'run_number' => $runNumber,
                'input_version' => config('profit_calculation.input_version'),
                'input_hash' => $inputHash,
                'input_key' => $inputKey,
                'currency_code' => strtoupper((string) $attributes['currency_code']),
                'source_country_code' => $lockedAnalysis->source_country_code,
                'target_country_code' => $lockedAnalysis->target_country_code,
                'regional_compatibility_confirmed' => (
                    $attributes['regional_compatibility_confirmed'] ?? null
                ),
                'known_count' => $knownCount,
                'unknown_count' => count($items) - $knownCount,
                'input_snapshot' => $inputSnapshot,
                'submitted_at' => now(),
            ]);
            $input->items()->createMany($items);

            return [
                'input' => $input->load('items'),
                'created' => true,
            ];
        }, attempts: 3);
    }
}
