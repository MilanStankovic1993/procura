<?php

namespace App\Enums\Outcomes;

enum OutcomeEvidenceKind: string
{
    case Receipt = 'receipt';
    case Invoice = 'invoice';
    case BankStatement = 'bank_statement';
    case MarketplaceRecord = 'marketplace_record';
    case ManualConfirmation = 'manual_confirmation';
    case Other = 'other';
}
