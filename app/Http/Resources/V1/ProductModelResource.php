<?php

namespace App\Http\Resources\V1;

use App\Models\ProductModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductModel */
class ProductModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'canonical_key' => $this->canonical_key,
            'name' => $this->name,
            'model_number' => $this->model_number,
            'brand' => $this->whenLoaded('brand', fn (): array => [
                'id' => $this->brand->getKey(),
                'name' => $this->brand->name,
            ]),
            'category' => $this->whenLoaded('category', fn (): array => [
                'id' => $this->category->getKey(),
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'specifications' => $this->specifications,
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(
                static fn ($variant): array => [
                    'id' => $variant->getKey(),
                    'canonical_key' => $variant->canonical_key,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'attributes' => $variant->attributes,
                    'markets' => $variant->relationLoaded('marketContexts')
                        ? $variant->marketContexts->map(
                            static fn ($market): array => [
                                'country_code' => $market->country_code,
                                'market_model_number' => $market->market_model_number,
                                'voltage_millivolts' => $market->voltage_millivolts,
                                'plug_type' => $market->plug_type,
                                'measurement_system' => $market->measurement_system,
                                'warranty_applicable' => $market->warranty_applicable,
                                'included_accessories' => $market->included_accessories,
                            ],
                        )->values()
                        : [],
                ],
            )->values()),
        ];
    }
}
