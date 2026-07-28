<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalePortfolioEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sell_listing_draft_id' => [
                'required',
                'string',
                'ulid',
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
