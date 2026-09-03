<?php

namespace App\Http\Resources\V1;

use App\Models\PrivacyRequestFulfillment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PrivacyRequestFulfillment */
final class PrivacyRequestFulfillmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'request_type' => $this->request_type->value,
            'execution_version' => $this->execution_version,
            'data_inventory_version' => $this->data_inventory_version,
            'artifact_size_bytes' => $this->artifact_size_bytes,
            'artifact_expires_at' => (
                $this->artifact_expires_at?->toIso8601String()
            ),
            'backup_purge_due_at' => (
                $this->backup_purge_due_at?->toIso8601String()
            ),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
