<?php

namespace App\Http\Requests\Api\V1\Analyses;

use App\Enums\BuyerDecisions\BuyerDecisionState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordBuyerDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['reason_code', 'note'] as $field) {
            if (! is_string($this->input($field))) {
                continue;
            }

            $value = trim($this->string($field)->toString());
            $normalized[$field] = $value === '' ? null : $value;
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'deal_score_id' => ['required', 'string', 'size:26'],
            'expected_current_event_id' => [
                'present',
                'nullable',
                'string',
                'size:26',
            ],
            'next_state' => [
                'required',
                Rule::enum(BuyerDecisionState::class),
            ],
            'reason_code' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'buyer_decisions.maximum_reason_code_length',
                ),
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
            ],
            'note' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'buyer_decisions.maximum_note_length',
                ),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
