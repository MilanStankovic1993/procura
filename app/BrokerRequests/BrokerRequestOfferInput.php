<?php

namespace App\BrokerRequests;

use App\Enums\BrokerRequests\BrokerProductCondition;
use App\Enums\Validation\ApplicationValidationCode;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class BrokerRequestOfferInput
{
    public function __construct(
        private readonly BrokerCommissionCalculator $commissionCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeAndValidate(array $input): array
    {
        $normalized = [
            'supplier_display_name' => trim(
                (string) ($input['supplier_display_name'] ?? ''),
            ),
            'supplier_reference' => trim(
                (string) ($input['supplier_reference'] ?? ''),
            ),
            'item_description' => trim(
                (string) ($input['item_description'] ?? ''),
            ),
            'condition' => (string) ($input['condition'] ?? ''),
            'quantity' => $input['quantity'] ?? null,
            'unit_price_minor' => $input['unit_price_minor'] ?? null,
            'shipping_cost_minor' => $input['shipping_cost_minor'] ?? 0,
            'tax_duty_cost_minor' => $input['tax_duty_cost_minor'] ?? 0,
            'other_cost_minor' => $input['other_cost_minor'] ?? 0,
            'currency_code' => mb_strtoupper(
                trim((string) ($input['currency_code'] ?? '')),
            ),
            'origin_country_code' => $this->nullableUppercaseString(
                $input['origin_country_code'] ?? null,
            ),
            'estimated_delivery_date' => $this->nullableString(
                $input['estimated_delivery_date'] ?? null,
            ),
            'valid_until' => trim((string) ($input['valid_until'] ?? '')),
            'warranty_months' => $input['warranty_months'] ?? null,
            'return_policy_summary' => $this->nullableString(
                $input['return_policy_summary'] ?? null,
            ),
        ];

        $validated = Validator::make($normalized, self::rules())->validate();
        $quantity = (int) $validated['quantity'];
        $unitPrice = (int) $validated['unit_price_minor'];

        if (
            $unitPrice > intdiv(
                BrokerCommissionCalculator::MAX_SAFE_MINOR,
                $quantity,
            )
        ) {
            ApplicationValidation::fail(
                'unit_price_minor',
                ApplicationValidationCode::BrokerOfferMoneyLimit,
            );
        }

        $validated['item_subtotal_minor'] = $unitPrice * $quantity;
        $validated['total_minor'] = $this->commissionCalculator->safeTotal([
            $validated['item_subtotal_minor'],
            (int) $validated['shipping_cost_minor'],
            (int) $validated['tax_duty_cost_minor'],
            (int) $validated['other_cost_minor'],
        ]);
        $validated = [
            ...$validated,
            ...$this->commissionTerms($validated['total_minor']),
        ];

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'supplier_display_name' => [
                'required',
                'string',
                'min:2',
                'max:160',
            ],
            'supplier_reference' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'item_description' => [
                'required',
                'string',
                'min:10',
                'max:3000',
            ],
            'condition' => [
                'required',
                Rule::in([
                    BrokerProductCondition::New->value,
                    BrokerProductCondition::Used->value,
                    BrokerProductCondition::Refurbished->value,
                ]),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'unit_price_minor' => [
                'required',
                'integer',
                'min:0',
                'max:'.BrokerCommissionCalculator::MAX_SAFE_MINOR,
            ],
            'shipping_cost_minor' => [
                'required',
                'integer',
                'min:0',
                'max:'.BrokerCommissionCalculator::MAX_SAFE_MINOR,
            ],
            'tax_duty_cost_minor' => [
                'required',
                'integer',
                'min:0',
                'max:'.BrokerCommissionCalculator::MAX_SAFE_MINOR,
            ],
            'other_cost_minor' => [
                'required',
                'integer',
                'min:0',
                'max:'.BrokerCommissionCalculator::MAX_SAFE_MINOR,
            ],
            'currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('active', true),
            ],
            'origin_country_code' => [
                'nullable',
                'string',
                'size:2',
                Rule::exists('countries', 'code')->where('active', true),
            ],
            'estimated_delivery_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
            'valid_until' => ['required', 'date', 'after:now'],
            'warranty_months' => [
                'nullable',
                'integer',
                'min:0',
                'max:600',
            ],
            'return_policy_summary' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function commissionTerms(int $baseMinor): array
    {
        $ruleVersion = trim(
            (string) config('broker.commission_rule_version', ''),
        );
        $rate = (int) config('broker.commission_rate_basis_points', 0);

        return $this->commissionCalculator->calculate(
            $baseMinor,
            $ruleVersion,
            $rate,
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
