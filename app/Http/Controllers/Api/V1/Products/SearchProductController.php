<?php

namespace App\Http\Controllers\Api\V1\Products;

use App\Catalog\CatalogTextNormalizer;
use App\Enums\Validation\ApplicationValidationCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Products\SearchProductRequest;
use App\Http\Resources\V1\ProductModelResource;
use App\Models\ProductModel;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchProductController extends Controller
{
    public function __invoke(SearchProductRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $term = CatalogTextNormalizer::normalize($validated['q']);

        if (strlen($term) < 2) {
            ApplicationValidation::fail(
                'q',
                ApplicationValidationCode::ProductSearchTooShort,
            );
        }

        $countryCode = isset($validated['country_code'])
            ? strtoupper($validated['country_code'])
            : null;
        $limit = (int) ($validated['per_page'] ?? 20);

        $models = ProductModel::query()
            ->where('active', true)
            ->where(static function ($query) use ($term): void {
                $query->where('normalized_name', 'like', "{$term}%")
                    ->orWhere('normalized_model_number', 'like', "{$term}%")
                    ->orWhere('canonical_key', 'like', "{$term}%")
                    ->orWhereHas(
                        'brand',
                        static fn ($query) => $query
                            ->where('active', true)
                            ->where('normalized_name', 'like', "{$term}%"),
                    )
                    ->orWhereHas(
                        'aliases',
                        static fn ($query) => $query
                            ->where('active', true)
                            ->where('normalized_alias', 'like', "{$term}%"),
                    );
            })
            ->whereHas('brand', static fn ($query) => $query->where('active', true))
            ->whereHas('category', static fn ($query) => $query->where('active', true))
            ->when($countryCode !== null, static function ($query) use ($countryCode): void {
                $query->where(static function ($query) use ($countryCode): void {
                    $query->whereDoesntHave(
                        'variants',
                        static fn ($query) => $query->where('active', true),
                    )->orWhereHas('variants', static function ($query) use (
                        $countryCode,
                    ): void {
                        $query->where('active', true)
                            ->where(static function ($query) use ($countryCode): void {
                                $query->whereDoesntHave('marketContexts')
                                    ->orWhereHas(
                                        'marketContexts',
                                        static fn ($query) => $query->where(
                                            'country_code',
                                            $countryCode,
                                        ),
                                    );
                            });
                    });
                });
            })
            ->with(['brand', 'category'])
            ->orderBy('canonical_key')
            ->limit($limit)
            ->get();

        return ProductModelResource::collection($models)->additional([
            'meta' => [
                'query' => $term,
                'country_code' => $countryCode,
                'limit' => $limit,
            ],
        ]);
    }
}
