<?php

namespace App\Http\Requests\Api\V1\Monitoring;

class UpdateSavedSearchRequest extends StoreSavedSearchRequest
{
    protected function prepareForValidation(): void
    {
        $reasonCode = $this->input('reason_code');
        parent::prepareForValidation();
        $this->merge(['reason_code' => $reasonCode]);
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'expected_current_version_id' => ['required', 'string', 'max:26'],
            'reason_code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
            ],
        ];
    }
}
