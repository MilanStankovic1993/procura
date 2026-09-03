<?php

namespace App\Http\Requests\Api\V1\Comparables;

use Illuminate\Foundation\Http\FormRequest;

class IndexComparableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
