<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use App\Enums\OwnedProducts\OwnedProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexOwnedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::enum(OwnedProductStatus::class)],
            'target_continent_code' => [
                'sometimes',
                'string',
                'size:2',
                Rule::exists('continents', 'code')->where('active', true),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ];
    }
}
