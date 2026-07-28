<?php

namespace App\Http\Requests\Api\V1\Analyses;

use App\Enums\Profit\CostCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmAnalysisCostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency_code'))) {
            $this->merge([
                'currency_code' => strtoupper($this->string('currency_code')->toString()),
            ]);
        }
    }

    public function rules(): array
    {
        $amountRules = [
            'nullable',
            'integer',
            'min:0',
            'max:'.(int) config('profit_calculation.maximum_amount_minor'),
        ];
        $rules = [
            'price_estimate_id' => ['required', 'string', 'size:26'],
            'risk_assessment_id' => ['required', 'string', 'size:26'],
            'currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'regional_compatibility_confirmed' => ['nullable', 'boolean'],
        ];

        foreach (CostCategory::cases() as $category) {
            $rules[$category->inputKey()] = $amountRules;
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            '*.max' => 'Each cost must remain within the configured exact-money hard bound.',
        ];
    }
}
