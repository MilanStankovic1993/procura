<?php

namespace App\Exceptions;

use App\Enums\Api\ApiErrorCode;
use RuntimeException;

class MarketplaceImportConflictException extends RuntimeException
{
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
