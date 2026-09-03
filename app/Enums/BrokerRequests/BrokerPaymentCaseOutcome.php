<?php

namespace App\Enums\BrokerRequests;

enum BrokerPaymentCaseOutcome: string
{
    case RefundConfirmed = 'refund_confirmed';
    case RefundRejected = 'refund_rejected';
    case DisputeWon = 'dispute_won';
    case DisputeLost = 'dispute_lost';

    public function supports(BrokerPaymentCaseType $type): bool
    {
        return match ($type) {
            BrokerPaymentCaseType::Refund => in_array(
                $this,
                [self::RefundConfirmed, self::RefundRejected],
                true,
            ),
            BrokerPaymentCaseType::Dispute => in_array(
                $this,
                [self::DisputeWon, self::DisputeLost],
                true,
            ),
        };
    }

    public function requiresPositiveAmount(): bool
    {
        return in_array($this, [self::RefundConfirmed, self::DisputeLost], true);
    }
}
