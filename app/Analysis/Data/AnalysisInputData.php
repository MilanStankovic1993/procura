<?php

namespace App\Analysis\Data;

final readonly class AnalysisInputData
{
    /**
     * @param  array<string, mixed>  $requestPayload
     */
    public function __construct(
        public string $analysisId,
        public string $inputHash,
        public array $requestPayload,
    ) {}
}
