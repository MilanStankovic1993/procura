<?php

namespace App\Http\Requests\Api\V1\Privacy;

use Illuminate\Foundation\Http\FormRequest;

final class CancelPrivacyRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'note' => $this->filled('note')
                ? trim((string) $this->input('note'))
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
            'expected_current_event_id' => [
                'required',
                'string',
                'size:26',
            ],
            'idempotency_key' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'min:3', 'max:1000'],
        ];
    }
}
