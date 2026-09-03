<?php

namespace App\SellListingContent;

use JsonException;
use RuntimeException;

final class SellListingVersionEvidence
{
    public function templateHash(string $listingLanguage): string
    {
        $path = resource_path(
            "sell-listing-templates/{$listingLanguage}.php",
        );
        $hash = hash_file('sha256', $path);

        if (! is_string($hash)) {
            throw new RuntimeException(
                'The Sell listing template could not be hashed.',
            );
        }

        return $hash;
    }

    /** @return array<string, int> */
    public function photoPolicy(): array
    {
        $policy = config('sell_listing_content.photo_readiness');

        if (! is_array($policy)) {
            throw new RuntimeException(
                'The Sell photo-readiness policy is not configured.',
            );
        }

        ksort($policy);

        return array_map(
            static fn (mixed $value): int => (int) $value,
            $policy,
        );
    }

    /**
     * @throws JsonException
     */
    public function photoPolicyHash(): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->photoPolicy(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
