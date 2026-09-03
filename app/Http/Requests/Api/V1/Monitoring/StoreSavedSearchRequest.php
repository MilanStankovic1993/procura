<?php

namespace App\Http\Requests\Api\V1\Monitoring;

use App\Enums\Markets\ContinentCode;
use App\Enums\Monitoring\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSavedSearchRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'active' => $this->input('active', true),
            'include_cross_border' => $this->input(
                'include_cross_border',
                false,
            ),
            'country_codes' => $this->input('country_codes', []),
            'required_keywords' => $this->input('required_keywords', []),
            'excluded_keywords' => $this->input('excluded_keywords', []),
            'notification_channels' => $this->input(
                'notification_channels',
                [NotificationChannel::InApp->value],
            ),
            'reason_code' => $this->input(
                'reason_code',
                'saved_search_created',
            ),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $keywordMaximum = (int) config(
            'monitoring.maximum_keywords_per_kind',
            20,
        );

        return [
            ...self::criteriaRules($keywordMaximum),
            'reason_code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $this->filled('minimum_price_minor')
                    && $this->filled('maximum_price_minor')
                    && (int) $this->input('minimum_price_minor')
                        > (int) $this->input('maximum_price_minor')
                ) {
                    $validator->errors()->add(
                        'maximum_price_minor',
                        'The maximum price must be at least the minimum price.',
                    );
                }

                $channels = $this->input('notification_channels', []);

                if (
                    is_array($channels)
                    && ! in_array(
                        NotificationChannel::InApp->value,
                        $channels,
                        true,
                    )
                ) {
                    $validator->errors()->add(
                        'notification_channels',
                        'In-app delivery is required for every saved search.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function criteriaRules(int $keywordMaximum): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'active' => ['required', 'boolean'],
            'product_category_id' => [
                'nullable',
                'string',
                Rule::exists('product_categories', 'id')->where('active', true),
            ],
            'brand_id' => [
                'nullable',
                'string',
                Rule::exists('brands', 'id')->where('active', true),
            ],
            'product_model_id' => [
                'nullable',
                'string',
                Rule::exists('product_models', 'id')->where('active', true),
            ],
            'minimum_price_minor' => [
                'nullable',
                'integer',
                'min:0',
                'max:9007199254740991',
            ],
            'maximum_price_minor' => [
                'nullable',
                'integer',
                'min:0',
                'max:9007199254740991',
            ],
            'price_currency_code' => [
                'nullable',
                'string',
                'size:3',
                'required_with:minimum_price_minor,maximum_price_minor',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'continent_code' => [
                'nullable',
                Rule::enum(ContinentCode::class),
            ],
            'country_codes' => ['required', 'array', 'max:20'],
            'country_codes.*' => [
                'string',
                'size:2',
                'distinct',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'city' => [
                'nullable',
                'string',
                'max:120',
                'required_with:radius_km',
            ],
            'radius_km' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'include_cross_border' => ['required', 'boolean'],
            'required_keywords' => [
                'required',
                'array',
                'max:'.$keywordMaximum,
            ],
            'required_keywords.*' => [
                'string',
                'min:1',
                'max:80',
                'distinct:ignore_case',
            ],
            'excluded_keywords' => [
                'required',
                'array',
                'max:'.$keywordMaximum,
            ],
            'excluded_keywords.*' => [
                'string',
                'min:1',
                'max:80',
                'distinct:ignore_case',
            ],
            'minimum_profit_minor' => [
                'nullable',
                'integer',
                'min:-9007199254740991',
                'max:9007199254740991',
                'required_with:profit_currency_code',
            ],
            'profit_currency_code' => [
                'nullable',
                'string',
                'size:3',
                'required_with:minimum_profit_minor',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'minimum_margin_basis_points' => [
                'nullable',
                'integer',
                'min:-100000',
                'max:1000000',
            ],
            'minimum_deal_score_basis_points' => [
                'nullable',
                'integer',
                'min:0',
                'max:10000',
            ],
            'maximum_risk_score' => [
                'nullable',
                'integer',
                'min:0',
                'max:100',
            ],
            'notification_channels' => [
                'required',
                'array',
                'min:1',
                'max:3',
            ],
            'notification_channels.*' => [
                'required',
                Rule::in([
                    NotificationChannel::InApp->value,
                    NotificationChannel::Email->value,
                    NotificationChannel::Telegram->value,
                ]),
                'distinct',
            ],
        ];
    }
}
