<?php

namespace App\ProductMatching\Data;

use InvalidArgumentException;

final readonly class ProductMatchingInputData
{
    /**
     * @param  list<string>  $marketCountryCodes
     */
    public function __construct(
        public string $inputHash,
        public string $title,
        public ?string $description,
        public array $marketCountryCodes,
        public string $targetCountryCode,
    ) {
        if ($marketCountryCodes === []) {
            throw new InvalidArgumentException(
                'Product matching requires at least one explicit market country.',
            );
        }

        foreach ($marketCountryCodes as $countryCode) {
            if (
                ! is_string($countryCode)
                || preg_match('/^[A-Z]{2}$/', $countryCode) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Product matching market countries must use ISO alpha-2 codes.',
                );
            }
        }
    }

    public function searchableText(): string
    {
        return trim($this->title.' '.($this->description ?? ''));
    }
}
