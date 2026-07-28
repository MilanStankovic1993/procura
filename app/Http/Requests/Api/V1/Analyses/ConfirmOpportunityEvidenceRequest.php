<?php

namespace App\Http\Requests\Api\V1\Analyses;

use App\Enums\Opportunity\ShippingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConfirmOpportunityEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('demand_evidence_source'))) {
            $this->merge([
                'demand_evidence_source' => trim(
                    $this->string('demand_evidence_source')->toString(),
                ),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'comparable_set_id' => ['required', 'string', 'size:26'],
            'price_estimate_id' => ['required', 'string', 'size:26'],
            'risk_assessment_id' => ['required', 'string', 'size:26'],
            'cost_input_id' => ['required', 'string', 'size:26'],
            'profit_estimate_id' => ['required', 'string', 'size:26'],
            'shipping_method' => [
                'nullable',
                Rule::enum(ShippingMethod::class),
            ],
            'shipping_distance_km' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.(int) config(
                    'opportunity_assessment.maximum_shipping_distance_km',
                ),
            ],
            'pickup_available' => ['nullable', 'boolean'],
            'tracking_available' => ['nullable', 'boolean'],
            'insurance_available' => ['nullable', 'boolean'],
            'packaging_confirmed' => ['nullable', 'boolean'],
            'cross_border_handling_confirmed' => ['nullable', 'boolean'],
            'sold_comparables_count' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.(int) config(
                    'opportunity_assessment.maximum_sold_comparables',
                ),
            ],
            'median_days_to_sale' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.(int) config(
                    'opportunity_assessment.maximum_median_days_to_sale',
                ),
            ],
            'observation_window_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.(int) config(
                    'opportunity_assessment.maximum_observation_window_days',
                ),
            ],
            'demand_evidence_observed_at' => [
                'nullable',
                'date',
                'before_or_equal:now',
            ],
            'demand_evidence_source' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'opportunity_assessment.maximum_source_length',
                ),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $soldCount = $this->input('sold_comparables_count');
                $medianDays = $this->input('median_days_to_sale');

                if (
                    is_int($soldCount)
                    && $soldCount > 0
                    && $medianDays === null
                ) {
                    $validator->errors()->add(
                        'median_days_to_sale',
                        'Median days to sale is required when sold comparables were observed.',
                    );
                }

                if ($soldCount === 0 && $medianDays !== null) {
                    $validator->errors()->add(
                        'median_days_to_sale',
                        'Median days to sale must be empty when no sold comparables were observed.',
                    );
                }

                if ($soldCount === null && $medianDays !== null) {
                    $validator->errors()->add(
                        'sold_comparables_count',
                        'Sold comparable count is required with sale-velocity evidence.',
                    );
                }
            },
        ];
    }
}
