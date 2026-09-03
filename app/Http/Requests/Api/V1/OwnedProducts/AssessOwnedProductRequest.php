<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use Illuminate\Foundation\Http\FormRequest;

class AssessOwnedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'owned_product_snapshot_id' => ['required', 'string', 'ulid'],
        ];
    }
}
