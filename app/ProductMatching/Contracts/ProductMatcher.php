<?php

namespace App\ProductMatching\Contracts;

use App\ProductMatching\Data\ProductMatchData;
use App\ProductMatching\Data\ProductMatchingInputData;

interface ProductMatcher
{
    public function match(ProductMatchingInputData $input): ProductMatchData;
}
