<?php

namespace App\Exceptions;

use RuntimeException;

final class AnalysisProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly ?int $providerStatus = null,
    ) {
        parent::__construct('The analysis provider request failed.');
    }
}
