<?php

namespace App\Http\Requests\Api\V1\Listings;

use App\Enums\Listings\ListingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::enum(ListingStatus::class)],
            'source_country_code' => [
                'sometimes',
                'string',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'target_country_code' => [
                'sometimes',
                'string',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ];
    }
}
