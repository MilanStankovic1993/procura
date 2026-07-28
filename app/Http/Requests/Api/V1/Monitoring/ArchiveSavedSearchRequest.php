<?php

namespace App\Http\Requests\Api\V1\Monitoring;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveSavedSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_current_version_id' => ['required', 'string', 'max:26'],
            'reason_code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
