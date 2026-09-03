<?php

namespace App\Http\Resources\V1;

use App\Models\ListingSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ListingSnapshot */
class ListingSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'sequence' => $this->sequence,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'source_url' => $this->source_url,
            'external_id' => $this->external_id,
            'marketplace_name' => $this->marketplace_name,
            'title' => $this->title,
            'description' => $this->description,
            'asking_price_minor' => $this->asking_price_minor,
            'currency_code' => $this->currency_code,
            'seller_information' => $this->seller_information,
            'location' => $this->location,
            'source_country_code' => $this->source_country_code,
            'target_country_code' => $this->target_country_code,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'content_hash' => $this->content_hash,
        ];
    }
}
