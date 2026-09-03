<?php

namespace App\BrokerRequests;

use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Models\BrokerRequest;

final class BrokerRequestSnapshot
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function fromInput(
        array $input,
        BrokerRequestStatus $status,
    ): array {
        return [
            'status' => $status->value,
            'title' => $input['title'],
            'product_category_id' => $input['product_category_id'],
            'product_description' => $input['product_description'],
            'brand_preference' => $input['brand_preference'],
            'model_preference' => $input['model_preference'],
            'condition_preference' => $input['condition_preference'],
            'quantity' => $input['quantity'],
            'budget_max_minor' => $input['budget_max_minor'],
            'budget_currency_code' => $input['budget_currency_code'],
            'target_country_codes' => $input['target_country_codes'],
            'needed_by' => $input['needed_by'],
            'notes' => $input['notes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromModel(
        BrokerRequest $request,
        ?BrokerRequestStatus $status = null,
    ): array {
        return [
            'status' => ($status ?? $request->status)->value,
            'title' => $request->title,
            'product_category_id' => $request->product_category_id,
            'product_description' => $request->product_description,
            'brand_preference' => $request->brand_preference,
            'model_preference' => $request->model_preference,
            'condition_preference' => $request->condition_preference->value,
            'quantity' => $request->quantity,
            'budget_max_minor' => $request->budget_max_minor,
            'budget_currency_code' => $request->budget_currency_code,
            'target_country_codes' => $request->target_country_codes,
            'needed_by' => $request->needed_by?->toDateString(),
            'notes' => $request->notes,
        ];
    }
}
