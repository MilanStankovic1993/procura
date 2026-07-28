<?php

namespace App\Enums\Profit;

enum CostCategory: string
{
    case PurchasePrice = 'purchase_price';
    case Transport = 'transport';
    case Repair = 'repair';
    case PlatformFees = 'platform_fees';
    case PaymentFees = 'payment_fees';
    case Customs = 'customs';
    case Tax = 'tax';
    case Other = 'other_costs';
    case SafetyReserve = 'safety_reserve';

    public function position(): int
    {
        return match ($this) {
            self::PurchasePrice => 1,
            self::Transport => 2,
            self::Repair => 3,
            self::PlatformFees => 4,
            self::PaymentFees => 5,
            self::Customs => 6,
            self::Tax => 7,
            self::Other => 8,
            self::SafetyReserve => 9,
        };
    }

    public function inputKey(): string
    {
        return "{$this->value}_minor";
    }

    /** @return list<self> */
    public static function additionalCosts(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $category): bool => $category !== self::PurchasePrice,
        ));
    }
}
