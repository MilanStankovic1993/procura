<?php

namespace App\Analysis\Contracts;

use App\Analysis\Data\AnalysisInputData;

interface ConfiguredListingAiAnalyzer extends ListingAiAnalyzer
{
    public function isConfigured(): bool;

    public function provider(): string;

    public function model(): string;

    public function maximumCostMinor(AnalysisInputData $input): int;
}
