<?php

namespace App\Http\Requests\Api\V1\Products;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => is_string($this->input('q')) ? trim($this->input('q')) : $this->input('q'),
            'country_code' => is_string($this->input('country_code'))
                ? strtoupper(trim($this->input('country_code')))
                : $this->input('country_code'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:80'],
            'country_code' => [
                'sometimes',
                'string',
                'size:2',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }
}
