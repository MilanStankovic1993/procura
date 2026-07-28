<?php

namespace App\Http\Requests\Api\V1\Comparables;

use App\Enums\Comparables\MarketCompatibilityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComparableMarketNormalizationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'compatibility_status' => strtolower(
                trim((string) $this->input('compatibility_status', '')),
            ),
            'evidence_confirmed' => $this->boolean('evidence_confirmed'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $compatible = $this->input('compatibility_status')
            === MarketCompatibilityStatus::Compatible->value;
        $maximumCost = (int) config(
            'market_normalization.maximum_cost_minor',
        );

        return [
            'compatibility_status' => [
                'required',
                Rule::enum(MarketCompatibilityStatus::class),
            ],
            'market_factor_basis_points' => [
                Rule::requiredIf($compatible),
                Rule::prohibitedIf(! $compatible),
                'nullable',
                'integer',
                'min:'.config(
                    'market_normalization.minimum_market_factor_basis_points',
                ),
                'max:'.config(
                    'market_normalization.maximum_market_factor_basis_points',
                ),
            ],
            'shipping_minor' => [
                Rule::prohibitedIf(! $compatible),
                'nullable',
                'integer',
                'min:0',
                "max:{$maximumCost}",
            ],
            'import_duty_minor' => [
                Rule::prohibitedIf(! $compatible),
                'nullable',
                'integer',
                'min:0',
                "max:{$maximumCost}",
            ],
            'tax_minor' => [
                Rule::prohibitedIf(! $compatible),
                'nullable',
                'integer',
                'min:0',
                "max:{$maximumCost}",
            ],
            'other_cost_minor' => [
                Rule::prohibitedIf(! $compatible),
                'nullable',
                'integer',
                'min:0',
                "max:{$maximumCost}",
            ],
            'evidence_reference' => [
                'required',
                'string',
                'max:2048',
            ],
            'compatibility_note' => [
                'required',
                'string',
                'max:5000',
            ],
            'observed_at' => [
                'required',
                'date',
                'before_or_equal:now',
            ],
            'evidence_confirmed' => ['required', 'accepted'],
        ];
    }
}
