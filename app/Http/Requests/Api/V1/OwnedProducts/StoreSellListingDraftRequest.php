<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use App\Enums\Sell\SellPriceStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSellListingDraftRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'target_country_code' => strtoupper((string) $this->input(
                'target_country_code',
                '',
            )),
            'target_currency_code' => strtoupper((string) $this->input(
                'target_currency_code',
                '',
            )),
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
            'sell_price_band_id' => ['required', 'string', 'ulid'],
            'target_country_code' => [
                'required',
                'string',
                'size:2',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'target_currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'price_strategy' => [
                'required',
                Rule::enum(SellPriceStrategy::class),
            ],
            'target_asking_price_minor' => [
                'required',
                'integer',
                'min:1',
                'max:9007199254740991',
            ],
            'listing_language' => [
                'required',
                'string',
                Rule::in(config(
                    'sell_listing_content.supported_languages',
                )),
            ],
            'price_override_reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}
