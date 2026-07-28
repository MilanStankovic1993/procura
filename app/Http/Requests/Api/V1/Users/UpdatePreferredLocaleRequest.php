<?php

namespace App\Http\Requests\Api\V1\Users;

use App\Enums\Localization\SupportedLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferredLocaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'preferred_locale' => [
                'required',
                'string',
                'max:35',
                Rule::enum(SupportedLocale::class),
            ],
        ];
    }
}
