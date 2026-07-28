<?php

namespace App\Http\Resources\V1;

use App\Models\ComparableRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComparableRecord */
class ComparableRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'product_model_id' => $this->product_model_id,
            'product_variant_id' => $this->product_variant_id,
            'product_variant' => $this->productVariant?->name,
            'source' => [
                'key' => $this->marketplaceSource?->key,
                'name' => $this->marketplaceSource?->name,
                'marketplace_name' => $this->marketplace_name,
                'source_url' => $this->source_url,
                'external_id' => $this->external_id,
                'source_identity_hash' => $this->source_identity_hash,
                'evidence_hash' => $this->evidence_hash,
            ],
            'title' => $this->title,
            'description' => $this->description,
            'listing_type' => $this->listing_type->value,
            'condition' => $this->condition_code->value,
            'seller_type' => $this->seller_type->value,
            'asking_price_minor' => $this->asking_price_minor,
            'currency_code' => $this->currency_code,
            'country_code' => $this->country_code,
            'location' => $this->location,
            'included_accessories' => $this->included_accessories,
            'missing_accessories' => $this->missing_accessories,
            'source_reliability_basis_points' => $this->source_reliability_basis_points,
            'published_at' => $this->published_at?->toIso8601String(),
            'observed_at' => $this->observed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
