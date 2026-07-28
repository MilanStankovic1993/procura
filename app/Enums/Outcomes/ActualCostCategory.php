<?php

namespace App\Enums\Outcomes;

enum ActualCostCategory: string
{
    case Transport = 'transport';
    case Repair = 'repair';
    case PlatformFees = 'platform_fees';
    case PaymentFees = 'payment_fees';
    case Customs = 'customs';
    case Tax = 'tax';
    case Marketing = 'marketing';
    case Other = 'other_costs';

    public function position(): int
    {
        return match ($this) {
            self::Transport => 1,
            self::Repair => 2,
            self::PlatformFees => 3,
            self::PaymentFees => 4,
            self::Customs => 5,
            self::Tax => 6,
            self::Marketing => 7,
            self::Other => 8,
        };
    }
}
