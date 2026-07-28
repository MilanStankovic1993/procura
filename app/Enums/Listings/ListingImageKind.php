<?php

namespace App\Enums\Listings;

enum ListingImageKind: string
{
    case Product = 'product';
    case Screenshot = 'screenshot';

    public function maximumCount(): int
    {
        return match ($this) {
            self::Product => (int) config('listings.uploads.max_product_images'),
            self::Screenshot => (int) config('listings.uploads.max_screenshots'),
        };
    }
}
