<?php

namespace App\Http\Requests\Api\V1\Privacy;

use App\Enums\Privacy\PrivacyRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePrivacyRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => $this->filled('reason')
                ? trim((string) $this->input('reason'))
                : null,
            'residence_country_code' => $this->filled(
                'residence_country_code',
            )
                ? strtoupper(trim((string) $this->input(
                    'residence_country_code',
                )))
                : null,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PrivacyRequestType::class)],
            'residence_country_code' => [
                'nullable',
                'string',
                'size:2',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'reason' => ['nullable', 'string', 'min:10', 'max:1000'],
            'privacy_notice_confirmed' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
