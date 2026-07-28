<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use App\Enums\Outcomes\OutcomeEvidenceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActualPurchaseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ([
            'evidence_reference',
            'correction_reason',
            'note',
        ] as $field) {
            if (! is_string($this->input($field))) {
                continue;
            }

            $value = trim((string) $this->input($field));
            $normalized[$field] = $value === '' ? null : $value;
        }

        foreach ([
            'currency_code',
            'reporting_currency_code',
        ] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = strtoupper(
                    trim((string) $this->input($field)),
                );
            }
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currency = Rule::exists('currencies', 'code')
            ->where('active', true);

        return [
            'expected_current_purchase_id' => [
                'present',
                'nullable',
                'string',
                'ulid',
            ],
            'amount_minor' => [
                'required',
                'integer',
                'min:0',
                'max:9007199254740991',
            ],
            'currency_code' => [
                'required',
                'string',
                'size:3',
                $currency,
            ],
            'reporting_currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'occurred_at' => [
                'required',
                'date',
                'before_or_equal:now',
            ],
            'evidence_kind' => [
                'required',
                Rule::enum(OutcomeEvidenceKind::class),
            ],
            'evidence_reference' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'outcome_tracking.maximum_reference_length',
                ),
            ],
            'correction_reason' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'outcome_tracking.maximum_reason_code_length',
                ),
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
            ],
            'note' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'outcome_tracking.maximum_note_length',
                ),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
