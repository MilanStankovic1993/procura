<?php

namespace App\Exceptions;

use App\Enums\Analyses\AnalysisStatus;
use RuntimeException;

class InvalidAnalysisTransition extends RuntimeException
{
    public function __construct(
        public readonly AnalysisStatus $status,
        string $message = 'The analysis cannot transition from its current status.',
    ) {
        parent::__construct($message);
    }
}
