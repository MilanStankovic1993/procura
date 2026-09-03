<?php

use App\Market\CountryReferenceData;
use Symfony\Component\Intl\Countries;

test('every official ISO country has exactly one continent assignment', function () {
    $mapping = CountryReferenceData::continentByCountry();
    $officialCodes = Countries::getCountryCodes();

    sort($officialCodes);
    $mappedCodes = array_keys($mapping);
    sort($mappedCodes);

    expect($mappedCodes)->toBe($officialCodes)
        ->and($mapping)->toHaveCount(249);
});
