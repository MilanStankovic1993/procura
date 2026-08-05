<?php

namespace App\Enums\BrokerRequests;

enum BrokerTransactionEventType: string
{
    case Opened = 'opened';
    case PaymentConfirmed = 'payment_confirmed';
    case SupplierOrdered = 'supplier_ordered';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
