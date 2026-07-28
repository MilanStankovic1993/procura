<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use App\Enums\Comparables\ComparableCondition;
use App\Enums\Comparables\ComparableListingType;
use App\Enums\Comparables\ComparableSellerType;
use App\Models\MarketplaceSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSellComparableRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'marketplace_source_key' => $this->input(
                'marketplace_source_key',
                MarketplaceSource::MANUAL_KEY,
            ),
            'currency_code' => strtoupper((string) $this->input(
                'currency_code',
                '',
            )),
            'country_code' => strtoupper((string) $this->input(
                'country_code',
                '',
            )),
            'listing_type' => $this->input(
                'listing_type',
                ComparableListingType::Product->value,
            ),
            'condition_code' => $this->input(
                'condition_code',
                ComparableCondition::Unknown->value,
            ),
            'seller_type' => $this->input(
                'seller_type',
                ComparableSellerType::Unknown->value,
            ),
            'included_accessories' => $this->input(
                'included_accessories',
                [],
            ),
            'missing_accessories' => $this->input(
                'missing_accessories',
                [],
            ),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'owned_product_assessment_id' => [
                'required',
                'string',
                'ulid',
            ],
            'marketplace_source_key' => [
                'required',
                Rule::in([MarketplaceSource::MANUAL_KEY]),
                Rule::exists('marketplace_sources', 'key')->where(
                    'active',
                    true,
                ),
            ],
            'product_variant_id' => [
                'nullable',
                'string',
                Rule::exists('product_variants', 'id')->where('active', true),
            ],
            'source_url' => [
                'nullable',
                'required_without:external_id',
                'url:http,https',
                'max:2048',
            ],
            'external_id' => [
                'nullable',
                'required_without:source_url',
                'string',
                'max:128',
            ],
            'marketplace_name' => ['required', 'string', 'max:160'],
            'title' => ['required', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:50000'],
            'listing_type' => [
                'required',
                Rule::enum(ComparableListingType::class),
            ],
            'condition_code' => [
                'required',
                Rule::enum(ComparableCondition::class),
            ],
            'seller_type' => [
                'required',
                Rule::enum(ComparableSellerType::class),
            ],
            'asking_price_minor' => [
                'required',
                'integer',
                'min:1',
                'max:9007199254740991',
            ],
            'currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'country_code' => [
                'required',
                'string',
                'size:2',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'included_accessories' => ['array', 'max:50'],
            'included_accessories.*' => [
                'string',
                'max:160',
                'distinct:ignore_case',
            ],
            'missing_accessories' => ['array', 'max:50'],
            'missing_accessories.*' => [
                'string',
                'max:160',
                'distinct:ignore_case',
            ],
            'published_at' => [
                'nullable',
                'date',
                'before_or_equal:observed_at',
            ],
            'observed_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }
}
