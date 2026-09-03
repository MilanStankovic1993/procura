<?php

namespace App\Monitoring;

use App\Enums\Validation\ApplicationValidationCode;
use App\Models\ProductModel;
use App\Support\Validation\ApplicationValidation;

final class SavedSearchCriteriaValidator
{
    /**
     * @param  array<string, mixed>  $criteria
     */
    public function validate(array $criteria): void
    {
        if ($criteria['product_model_id'] === null) {
            return;
        }

        $model = ProductModel::query()
            ->whereKey($criteria['product_model_id'])
            ->where('active', true)
            ->firstOrFail();

        /** @var array<string, ApplicationValidationCode> $errors */
        $errors = [];

        if (
            $criteria['brand_id'] !== null
            && $criteria['brand_id'] !== $model->brand_id
        ) {
            $errors['brand_id'] =
                ApplicationValidationCode::SavedSearchBrandModelMismatch;
        }

        if (
            $criteria['product_category_id'] !== null
            && $criteria['product_category_id'] !== $model->product_category_id
        ) {
            $errors['product_category_id'] =
                ApplicationValidationCode::SavedSearchCategoryModelMismatch;
        }

        if ($errors !== []) {
            ApplicationValidation::failMany($errors);
        }
    }
}
