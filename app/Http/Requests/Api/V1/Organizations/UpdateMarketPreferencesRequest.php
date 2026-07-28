<?php

namespace App\Http\Requests\Api\V1\Organizations;

use App\Enums\Markets\MeasurementSystem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMarketPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'home_country_code' => [
                'nullable', 'string',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'reporting_currency_code' => [
                'nullable', 'string',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'locale' => ['required', 'string', 'max:35'],
            'timezone' => ['required', 'timezone:all'],
            'measurement_system' => ['required', Rule::enum(MeasurementSystem::class)],
            'include_cross_border' => ['required', 'boolean'],
            'country_codes' => ['required', 'array', 'min:1', 'max:249'],
            'country_codes.*' => [
                'required', 'distinct', 'string',
                Rule::exists('countries', 'code')->where('active', true),
            ],
        ];
    }
}
