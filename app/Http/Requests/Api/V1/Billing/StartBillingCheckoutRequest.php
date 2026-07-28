<?php

namespace App\Http\Requests\Api\V1\Billing;

use App\Enums\Subscriptions\BillingInterval;
use App\Enums\Subscriptions\PlanCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartBillingCheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'plan' => [
                'required',
                Rule::enum(PlanCode::class),
                Rule::notIn([PlanCode::Free->value, PlanCode::Business->value]),
            ],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
            'idempotency_key' => [
                'required',
                'string',
                'min:16',
                'max:100',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]+$/',
            ],
        ];
    }
}
