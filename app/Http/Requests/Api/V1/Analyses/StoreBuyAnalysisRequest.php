<?php

namespace App\Http\Requests\Api\V1\Analyses;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBuyAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'listing_id' => ['required', 'string', 'size:26'],
            'target_country_code' => [
                'required',
                'string',
                'size:2',
                Rule::exists('countries', 'code')->where('active', true),
            ],
        ];
    }
}
