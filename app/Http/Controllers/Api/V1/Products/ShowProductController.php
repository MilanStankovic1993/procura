<?php

namespace App\Http\Controllers\Api\V1\Products;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductModelResource;
use App\Models\ProductModel;

class ShowProductController extends Controller
{
    public function __invoke(string $productModel): ProductModelResource
    {
        $record = ProductModel::query()
            ->where('active', true)
            ->whereHas('brand', static fn ($query) => $query->where('active', true))
            ->whereHas('category', static fn ($query) => $query->where('active', true))
            ->with([
                'brand',
                'category',
                'variants' => static fn ($query) => $query
                    ->where('active', true)
                    ->orderBy('canonical_key')
                    ->limit(50),
                'variants.marketContexts' => static fn ($query) => $query
                    ->orderBy('country_code'),
            ])
            ->findOrFail($productModel);

        return new ProductModelResource($record);
    }
}
