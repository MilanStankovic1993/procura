<?php

namespace App\Http\Resources\V1;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductCategory */
class ProductCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
        ];
    }
}
