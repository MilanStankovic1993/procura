<?php

namespace App\Http\Resources\V1;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Listing */
class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'marketplace_source' => new MarketplaceSourceResource(
                $this->whenLoaded('marketplaceSource'),
            ),
            'source_url' => $this->source_url,
            'external_id' => $this->external_id,
            'marketplace_name' => $this->marketplace_name,
            'title' => $this->title,
            'description' => $this->description,
            'asking_price_minor' => $this->asking_price_minor,
            'currency_code' => $this->currency_code,
            'currency_minor_unit' => $this->whenLoaded(
                'currency',
                fn (): ?int => $this->currency?->minor_unit,
            ),
            'seller_information' => $this->seller_information,
            'location' => $this->location,
            'source_country_code' => $this->source_country_code,
            'target_country_code' => $this->target_country_code,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'image_count' => $this->whenCounted('images'),
            'snapshot_count' => $this->whenCounted('snapshots'),
            'images' => ListingImageResource::collection($this->whenLoaded('images')),
            'snapshots' => ListingSnapshotResource::collection(
                $this->whenLoaded('snapshots'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
