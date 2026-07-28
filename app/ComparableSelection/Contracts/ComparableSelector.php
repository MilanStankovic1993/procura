<?php

namespace App\ComparableSelection\Contracts;

use App\ComparableSelection\Data\ComparableSelectionData;
use App\Models\Analysis;
use App\Models\ProductMatch;

interface ComparableSelector
{
    /**
     * @param  array<string, mixed>  $normalizedListing
     */
    public function select(
        Analysis $analysis,
        ProductMatch $productMatch,
        array $normalizedListing,
    ): ComparableSelectionData;
}
