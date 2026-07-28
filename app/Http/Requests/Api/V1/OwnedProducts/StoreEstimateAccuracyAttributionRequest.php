<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use App\Enums\Outcomes\OutcomeEvidenceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEstimateAccuracyAttributionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ([
            'reason_code',
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

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_current_attribution_id' => [
                'present',
                'nullable',
                'string',
                'ulid',
            ],
            'expected_current_accuracy_report_id' => [
                'present',
                'nullable',
                'string',
                'ulid',
            ],
            'realized_profit_id' => [
                'required',
                'string',
                'ulid',
            ],
            'analysis_id' => ['required', 'string', 'ulid'],
            'profit_estimate_id' => ['required', 'string', 'ulid'],
            'reason_code' => [
                'required',
                'string',
                'max:'.(int) config(
                    'estimate_accuracy.maximum_reason_code_length',
                ),
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
            ],
            'evidence_kind' => [
                'required',
                Rule::enum(OutcomeEvidenceKind::class),
            ],
            'evidence_reference' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'estimate_accuracy.maximum_reference_length',
                ),
            ],
            'correction_reason' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'estimate_accuracy.maximum_reason_code_length',
                ),
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
            ],
            'note' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'estimate_accuracy.maximum_note_length',
                ),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
