<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use Illuminate\Foundation\Http\FormRequest;

class IndexEstimateAccuracyCandidateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $search = trim((string) $this->input('search'));
            $this->merge(['search' => $search === '' ? null : $search]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
