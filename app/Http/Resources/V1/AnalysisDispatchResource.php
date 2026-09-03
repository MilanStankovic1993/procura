<?php

namespace App\Http\Resources\V1;

use App\Models\AnalysisDispatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AnalysisDispatch */
class AnalysisDispatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'run_number' => $this->run_number,
            'pipeline_version' => $this->pipeline_version,
            'status' => $this->status->value,
            'queue_name' => $this->queue_name,
            'dispatch_attempts' => $this->dispatch_attempts,
            'max_processing_attempts' => $this->max_processing_attempts,
            'available_at' => $this->available_at?->toIso8601String(),
            'last_dispatch_attempt_at' => $this->last_dispatch_attempt_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
