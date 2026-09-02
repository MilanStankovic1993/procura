<?php

namespace App\Analysis\Contracts;

interface ConfiguredListingAiAnalyzer extends ListingAiAnalyzer
{
    public function isConfigured(): bool;

    public function model(): string;
}
