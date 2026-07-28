<?php

namespace App\Http\Requests\Api\V1\OwnedProducts;

use App\Enums\Sell\SalePortfolioEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalePortfolioEventRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ([
            'marketplace_name',
            'marketplace_key',
            'external_listing_id',
            'external_listing_url',
            'reason_code',
            'note',
        ] as $field) {
            if (! is_string($this->input($field))) {
                continue;
            }

            $value = trim($this->string($field)->toString());
            $normalized[$field] = $value === '' ? null : $value;
        }

        if (isset($normalized['marketplace_key'])) {
            $normalized['marketplace_key'] = strtolower(
                $normalized['marketplace_key'],
            );
        }

        if (is_string($this->input('advertised_currency_code'))) {
            $normalized['advertised_currency_code'] = strtoupper(
                trim((string) $this->input('advertised_currency_code')),
            );
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_current_event_id' => [
                'present',
                'nullable',
                'string',
                'ulid',
            ],
            'event_type' => [
                'required',
                Rule::enum(SalePortfolioEventType::class),
            ],
            'marketplace_name' => [
                'nullable',
                'required_if:event_type,published,relisted',
                'string',
                'max:'.(int) config(
                    'sale_portfolio.maximum_marketplace_name_length',
                ),
            ],
            'marketplace_key' => [
                'nullable',
                'required_if:event_type,published,relisted',
                'string',
                'max:'.(int) config(
                    'sale_portfolio.maximum_marketplace_key_length',
                ),
                'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
            ],
            'external_listing_id' => [
                'nullable',
                'required_if:event_type,published,relisted',
                'string',
                'max:'.(int) config(
                    'sale_portfolio.maximum_external_id_length',
                ),
            ],
            'external_listing_url' => [
                'nullable',
                'required_if:event_type,published,relisted',
                'url:https',
                'max:'.(int) config('sale_portfolio.maximum_url_length'),
            ],
            'advertised_price_minor' => [
                'nullable',
                'required_if:event_type,published,price_changed,relisted',
                'integer',
                'min:1',
                'max:9007199254740991',
            ],
            'advertised_currency_code' => [
                'nullable',
                'required_if:event_type,published,price_changed,relisted',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'reason_code' => [
                'nullable',
                'required_if:event_type,withdrawn',
                'string',
                'max:'.(int) config(
                    'sale_portfolio.maximum_reason_code_length',
                ),
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
            ],
            'note' => [
                'nullable',
                'string',
                'max:'.(int) config(
                    'sale_portfolio.maximum_note_length',
                ),
            ],
            'occurred_at' => [
                'required',
                'date',
                'before_or_equal:now',
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
