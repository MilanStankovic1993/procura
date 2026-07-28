<?php

namespace App\Http\Controllers\Api\V1\Products;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductCategoryResource;
use App\Models\ProductCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductCategoryIndexController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return ProductCategoryResource::collection(
            ProductCategory::query()
                ->where('active', true)
                ->orderBy('name')
                ->limit(250)
                ->get(),
        );
    }
}
