<?php

namespace App\Http\Resources\V1;

use App\Models\Analysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Analysis */
class AnalysisSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'listing' => $this->whenLoaded('listing', fn (): array => [
                'id' => $this->listing->getKey(),
                'title' => $this->listing->title,
                'marketplace_name' => $this->listing->marketplace_name,
                'asking_price_minor' => $this->listing->asking_price_minor,
                'currency_code' => $this->listing->currency_code,
            ]),
            'analysis_type' => $this->analysis_type->value,
            'status' => $this->status->value,
            'source_country_code' => $this->source_country_code,
            'target_country_code' => $this->target_country_code,
            'pipeline_version' => $this->pipeline_version,
            'processing_attempts' => $this->processing_attempts,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'last_error_code' => $this->last_error_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
