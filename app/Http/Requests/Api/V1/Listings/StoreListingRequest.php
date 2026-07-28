<?php

namespace App\Http\Requests\Api\V1\Listings;

use App\Enums\Listings\ListingStatus;
use App\Models\MarketplaceSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreListingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'marketplace_source_key' => $this->input(
                'marketplace_source_key',
                MarketplaceSource::MANUAL_KEY,
            ),
            'status' => $this->input('status', ListingStatus::Unknown->value),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'marketplace_source_key' => [
                'required',
                'string',
                Rule::exists('marketplace_sources', 'key')->where('active', true),
            ],
            ...$this->listingRules(required: true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function listingRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'external_id' => ['nullable', 'string', 'max:128'],
            'marketplace_name' => [$presence, 'string', 'max:160'],
            'title' => [$presence, 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:50000'],
            'asking_price_minor' => [
                'nullable',
                'integer',
                'min:0',
                'max:9007199254740991',
                'required_with:currency_code',
            ],
            'currency_code' => [
                'nullable',
                'string',
                'size:3',
                'required_with:asking_price_minor',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'seller_information' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'source_country_code' => [
                $presence,
                'string',
                'size:2',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'target_country_code' => [
                $presence,
                'string',
                'size:2',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'status' => [$presence, Rule::enum(ListingStatus::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
