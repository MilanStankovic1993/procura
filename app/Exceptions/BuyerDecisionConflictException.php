<?php

namespace App\Exceptions;

use App\Enums\Api\ApiErrorCode;
use RuntimeException;

class BuyerDecisionConflictException extends RuntimeException
{
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
