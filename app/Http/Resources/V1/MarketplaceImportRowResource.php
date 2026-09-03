<?php

namespace App\Http\Resources\V1;

use App\Models\MarketplaceImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MarketplaceImportRow */
final class MarketplaceImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'row_number' => $this->row_number,
            'status' => $this->status->value,
            'listing_id' => $this->listing_id,
            'raw_payload' => $this->raw_payload,
            'normalized_payload' => $this->normalized_payload,
            'validation_errors' => $this->validation_errors,
            'row_hash' => $this->row_hash,
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
