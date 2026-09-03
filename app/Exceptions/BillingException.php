<?php

namespace App\Exceptions;

use App\Enums\Api\ApiErrorCode;
use RuntimeException;

class BillingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ApiErrorCode $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
