<?php

namespace App\Exceptions;

use RuntimeException;

final class AnalysisRetryConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
