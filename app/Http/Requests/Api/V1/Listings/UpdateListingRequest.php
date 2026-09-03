<?php

namespace App\Http\Requests\Api\V1\Listings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return StoreListingRequest::listingRules(required: false);
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $editableFields = array_keys(StoreListingRequest::listingRules(required: false));

                if ($this->collect()->only($editableFields)->isEmpty()) {
                    $validator->errors()->add(
                        'listing',
                        'At least one editable listing field is required.',
                    );
                }
            },
        ];
    }
}
