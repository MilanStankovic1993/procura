<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use App\Enums\Outcomes\ActualCostCategory;
use App\Enums\Outcomes\OutcomeEvidenceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActualCostSnapshotRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['correction_reason', 'note'] as $field) {
            if (! is_string($this->input($field))) {
                continue;
            }

            $value = trim((string) $this->input($field));
            $normalized[$field] = $value === '' ? null : $value;
        }

        if (is_string($this->input('reporting_currency_code'))) {
            $normalized['reporting_currency_code'] = strtoupper(
                trim((string) $this->input('reporting_currency_code')),
            );
        }

        if (is_array($this->input('items'))) {
            $normalized['items'] = array_map(
                static function (mixed $candidate): mixed {
                    if (! is_array($candidate)) {
                        return $candidate;
                    }

                    foreach ([
                        'evidence_reference',
                        'note',
                    ] as $field) {
                        if (! is_string($candidate[$field] ?? null)) {
                            continue;
                        }

                        $value = trim((string) $candidate[$field]);
                        $candidate[$field] = $value === '' ? null : $value;
                    }

                    if (is_string($candidate['currency_code'] ?? null)) {
                        $candidate['currency_code'] = strtoupper(
                            trim((string) $candidate['currency_code']),
                        );
                    }

                    return $candidate;
                },
                $this->input('items'),
            );
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
            'expected_current_cost_snapshot_id' => [
                'present',
                'nullable',
                'string',
                'ulid',
            ],
            'reporting_currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'items' => [
                'required',
                'array',
                'size:'.count(ActualCostCategory::cases()),
            ],
            'items.*' => ['required', 'array'],
            'items.*.category' => [
                'required',
                'distinct',
                Rule::enum(ActualCostCategory::class),
            ],
            'items.*.is_known' => ['required', 'boolean'],
            'items.*.amount_minor' => [
                'nullable',
                'integer',
                'min:0',
                'max:9007199254740991',
            ],
            'items.*.currency_code' => [
                'nullable',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'items.*.occurred_at' => [
                'nullable',
                'date',
                'before_or_equal:now',
            ],
            'items.*.evidence_kind' => [
                'nullable',
                Rule::enum(OutcomeEvidenceKind::class),
            ],
            'items.*.evidence_reference' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'outcome_tracking.maximum_reference_length',
                ),
            ],
            'items.*.note' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'outcome_tracking.maximum_note_length',
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
