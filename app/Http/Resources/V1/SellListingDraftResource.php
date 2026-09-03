<?php

namespace App\Http\Resources\V1;

use App\Models\SellListingDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SellListingDraft */
class SellListingDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'owned_product_assessment_id' => (
                $this->owned_product_assessment_id
            ),
            'sell_price_band_id' => $this->sell_price_band_id,
            'run_number' => $this->run_number,
            'status' => $this->status->value,
            'photo_readiness_status' => (
                $this->photo_readiness_status->value
            ),
            'photo_readiness_basis_points' => (
                $this->photo_readiness_basis_points
            ),
            'listing_language' => $this->listing_language,
            'template_version' => $this->template_version,
            'generator_version' => $this->generator_version,
            'photo_evaluator_version' => $this->photo_evaluator_version,
            'input_hash' => $this->input_hash,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'target_country_code' => $this->target_country_code,
            'target_currency_code' => $this->target_currency_code,
            'price_strategy' => $this->price_strategy->value,
            'target_asking_price_minor' => (
                $this->target_asking_price_minor
            ),
            'selected_band' => [
                'low_minor' => $this->selected_band_low_minor,
                'high_minor' => $this->selected_band_high_minor,
            ],
            'price_override_reason' => $this->price_override_reason,
            'title' => $this->title,
            'description' => $this->description,
            'completeness_basis_points' => (
                $this->completeness_basis_points
            ),
            'reason_codes' => $this->reason_codes,
            'unknown_facts' => $this->unknown_facts,
            'warnings' => $this->warnings,
            'verification_actions' => $this->verification_actions,
            'source_fact_identifiers' => $this->source_fact_identifiers,
            'facts' => $this->whenLoaded(
                'facts',
                fn () => $this->facts->map(static fn ($fact): array => [
                    'id' => $fact->getKey(),
                    'position' => $fact->position,
                    'fact_code' => $fact->fact_code,
                    'source_kind' => $fact->source_kind,
                    'source_id' => $fact->source_id,
                    'source_field' => $fact->source_field,
                    'is_unknown' => $fact->is_unknown,
                    'disclosure' => $fact->disclosure,
                    'value' => $fact->value_snapshot,
                ])->values()->all(),
            ),
            'photo_checklist' => $this->whenLoaded(
                'photoChecklist',
                fn () => $this->photoChecklist->map(
                    static fn ($item): array => [
                        'id' => $item->getKey(),
                        'position' => $item->position,
                        'check_code' => $item->check_code,
                        'status' => $item->status->value,
                        'required' => $item->required,
                        'image_kind' => $item->image_kind?->value,
                        'minimum_count' => $item->minimum_count,
                        'observed_count' => $item->observed_count,
                        'matching_image_ids' => $item->matching_image_ids,
                        'reason_codes' => $item->reason_codes,
                        'verification_actions' => (
                            $item->verification_actions
                        ),
                    ],
                )->values()->all(),
            ),
            'generated_by' => $this->whenLoaded(
                'generatedBy',
                fn (): ?array => $this->generatedBy === null
                    ? null
                    : [
                        'id' => $this->generatedBy->getKey(),
                        'name' => $this->generatedBy->name,
                    ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
