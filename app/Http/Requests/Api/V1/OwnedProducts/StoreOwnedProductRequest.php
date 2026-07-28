<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use App\Enums\OwnedProducts\CrossBorderPreference;
use App\Enums\OwnedProducts\DesiredSaleSpeed;
use App\Enums\OwnedProducts\OwnedProductCondition;
use App\Enums\OwnedProducts\OwnedProductStatus;
use App\Models\Country;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOwnedProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'condition' => $this->input(
                'condition',
                OwnedProductCondition::Unknown->value,
            ),
            'purchase_history_known' => $this->boolean('purchase_history_known'),
            'cross_border_preference' => $this->input(
                'cross_border_preference',
                CrossBorderPreference::Unknown->value,
            ),
            'desired_sale_speed' => $this->input(
                'desired_sale_speed',
                DesiredSaleSpeed::Unknown->value,
            ),
            'status' => $this->input('status', OwnedProductStatus::Draft->value),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->intakeRules(required: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function intakeRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';
        $listPresence = $required ? 'present' : 'sometimes';

        return [
            'product_category_id' => [
                'nullable',
                'string',
                Rule::exists('product_categories', 'id')->where('active', true),
            ],
            'brand_name' => ['nullable', 'string', 'max:160'],
            'model_name' => ['nullable', 'string', 'max:200'],
            'condition' => [$presence, Rule::enum(OwnedProductCondition::class)],
            'age_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'accessories' => [$listPresence, 'nullable', 'array', 'max:50'],
            'accessories.*' => ['required', 'string', 'max:160', 'distinct'],
            'defects' => [$listPresence, 'nullable', 'array', 'max:50'],
            'defects.*' => ['required', 'string', 'max:500', 'distinct'],
            'purchase_history_known' => [$presence, 'boolean'],
            'purchase_history' => ['nullable', 'string', 'max:10000'],
            'target_continent_code' => [
                $presence,
                'string',
                'size:2',
                Rule::exists('continents', 'code')->where('active', true),
            ],
            'target_country_codes' => [$presence, 'array', 'min:1', 'max:20'],
            'target_country_codes.*' => [
                'required',
                'string',
                'size:2',
                'distinct',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'cross_border_preference' => [
                $presence,
                Rule::enum(CrossBorderPreference::class),
            ],
            'desired_sale_speed' => [$presence, Rule::enum(DesiredSaleSpeed::class)],
            'status' => $required
                ? [
                    'required',
                    Rule::in([
                        OwnedProductStatus::Draft->value,
                        OwnedProductStatus::Ready->value,
                    ]),
                ]
                : ['sometimes', Rule::enum(OwnedProductStatus::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $this->has('purchase_history_known')
                    && ! $this->boolean('purchase_history_known')
                    && $this->filled('purchase_history')
                ) {
                    $validator->errors()->add(
                        'purchase_history',
                        'Purchase history must stay empty while it is marked unknown.',
                    );
                }

                $countryCodes = $this->input('target_country_codes');
                $continentCode = $this->input('target_continent_code');

                if (! is_array($countryCodes) || ! is_string($continentCode)) {
                    return;
                }

                $outsideCount = Country::query()
                    ->whereIn('code', $countryCodes)
                    ->where('continent_code', '!=', $continentCode)
                    ->count();

                if ($outsideCount > 0) {
                    $validator->errors()->add(
                        'target_country_codes',
                        'Every target country must belong to the selected continent.',
                    );
                }
            },
        ];
    }
}
