<?php

namespace App\BrokerRequests;

use App\Enums\BrokerRequests\BrokerProductCondition;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class BrokerRequestInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeAndValidate(array $input): array
    {
        $normalized = [
            'title' => trim((string) ($input['title'] ?? '')),
            'product_category_id' => $this->nullableString(
                $input['product_category_id'] ?? null,
            ),
            'product_description' => trim(
                (string) ($input['product_description'] ?? ''),
            ),
            'brand_preference' => $this->nullableString(
                $input['brand_preference'] ?? null,
            ),
            'model_preference' => $this->nullableString(
                $input['model_preference'] ?? null,
            ),
            'condition_preference' => (string) (
                $input['condition_preference']
                    ?? BrokerProductCondition::Any->value
            ),
            'quantity' => $input['quantity'] ?? 1,
            'budget_max_minor' => $input['budget_max_minor'] ?? null,
            'budget_currency_code' => $this->nullableUppercaseString(
                $input['budget_currency_code'] ?? null,
            ),
            'target_country_codes' => array_map(
                static fn (mixed $code): string => mb_strtoupper(
                    trim((string) $code),
                ),
                Arr::wrap($input['target_country_codes'] ?? []),
            ),
            'needed_by' => $this->nullableString($input['needed_by'] ?? null),
            'notes' => $this->nullableString($input['notes'] ?? null),
        ];

        return Validator::make($normalized, self::rules())->validate();
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'product_category_id' => [
                'nullable',
                'string',
                Rule::exists('product_categories', 'id')->where('active', true),
            ],
            'product_description' => [
                'required',
                'string',
                'min:20',
                'max:3000',
            ],
            'brand_preference' => ['nullable', 'string', 'max:120'],
            'model_preference' => ['nullable', 'string', 'max:160'],
            'condition_preference' => [
                'required',
                Rule::enum(BrokerProductCondition::class),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'budget_max_minor' => [
                'nullable',
                'integer',
                'min:0',
                'max:9007199254740991',
                'required_with:budget_currency_code',
            ],
            'budget_currency_code' => [
                'nullable',
                'string',
                'size:3',
                'required_with:budget_max_minor',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'target_country_codes' => [
                'required',
                'array',
                'min:1',
                'max:20',
            ],
            'target_country_codes.*' => [
                'required',
                'string',
                'size:2',
                'distinct',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'needed_by' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function hash(array $value): string
    {
        return hash(
            'sha256',
            json_encode(
                $value,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableUppercaseString(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : mb_strtoupper($value);
    }
}
