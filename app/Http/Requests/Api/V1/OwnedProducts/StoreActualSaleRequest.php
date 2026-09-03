<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use App\Enums\Outcomes\ActualSaleOutcomeType;
use App\Enums\Outcomes\OutcomeEvidenceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActualSaleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ([
            'evidence_reference',
            'reason_code',
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
        return [
            'expected_current_sale_id' => [
                'present',
                'nullable',
                'string',
                'ulid',
            ],
            'sale_portfolio_event_id' => [
                'required',
                'string',
                'ulid',
            ],
            'outcome_type' => [
                'required',
                Rule::enum(ActualSaleOutcomeType::class),
            ],
            'amount_minor' => [
                'nullable',
                'required_if:outcome_type,sold',
                'integer',
                'min:1',
                'max:9007199254740991',
            ],
            'currency_code' => [
                'nullable',
                'required_if:outcome_type,sold',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'reporting_currency_code' => [
                'nullable',
                'required_if:outcome_type,sold',
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
            'reason_code' => [
                'nullable',
                'required_if:outcome_type,cancelled,no_sale',
                'string',
                'max:'.(int) config(
                    'outcome_tracking.maximum_reason_code_length',
                ),
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
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
