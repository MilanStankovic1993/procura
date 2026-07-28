<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use Illuminate\Validation\Validator;

class UpdateOwnedProductRequest extends StoreOwnedProductRequest
{
    protected function prepareForValidation(): void
    {
        // Partial updates must not receive creation defaults.
    }

    public function rules(): array
    {
        return $this->intakeRules(required: false);
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $editableFields = array_keys($this->intakeRules(required: false));

                if ($this->collect()->only($editableFields)->isEmpty()) {
                    $validator->errors()->add(
                        'owned_product',
                        'At least one editable owned-product field is required.',
                    );
                }

                $hasContinent = $this->has('target_continent_code');
                $hasCountries = $this->has('target_country_codes');

                if ($hasContinent !== $hasCountries) {
                    $validator->errors()->add(
                        $hasContinent
                            ? 'target_country_codes'
                            : 'target_continent_code',
                        'Target continent and target countries must be updated together.',
                    );
                }
            },
        ];
    }
}
