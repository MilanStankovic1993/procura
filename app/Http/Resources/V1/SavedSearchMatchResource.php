<?php

namespace App\Http\Resources\V1;

use App\Models\SavedSearchMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SavedSearchMatch */
class SavedSearchMatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'saved_search_id' => $this->saved_search_id,
            'saved_search_version_id' => $this->saved_search_version_id,
            'listing' => [
                'id' => $this->listing_id,
                'title' => $this->listing?->title,
                'asking_price_minor' => $this->listing?->asking_price_minor,
                'currency_code' => $this->listing?->currency_code,
                'source_country_code' => $this->listing?->source_country_code,
                'target_country_code' => $this->listing?->target_country_code,
            ],
            'listing_snapshot_id' => $this->listing_snapshot_id,
            'analysis_id' => $this->analysis_id,
            'status' => $this->status->value,
            'matcher_version' => $this->matcher_version,
            'reason_codes' => $this->reason_codes,
            'unknown_criteria' => $this->unknown_criteria,
            'evaluated_at' => $this->evaluated_at?->toIso8601String(),
        ];
    }
}
