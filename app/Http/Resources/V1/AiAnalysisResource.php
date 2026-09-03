<?php

namespace App\Http\Resources\V1;

use App\Models\AiAnalysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiAnalysis */
class AiAnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'attempt_number' => $this->attempt_number,
            'status' => $this->status->value,
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt_version' => $this->prompt_version,
            'input_hash' => $this->input_hash,
            'result' => $this->result_json,
            'validation_status' => $this->validation_status->value,
            'confidence_basis_points' => $this->confidence_basis_points,
            'tokens_in' => $this->tokens_in,
            'tokens_out' => $this->tokens_out,
            'estimated_cost_minor' => $this->estimated_cost_minor,
            'estimated_cost_currency' => $this->estimated_cost_currency,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'error' => $this->error,
        ];
    }
}
