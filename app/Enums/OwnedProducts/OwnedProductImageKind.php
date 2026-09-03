<?php

namespace App\Enums\OwnedProducts;

enum OwnedProductImageKind: string
{
    case Product = 'product';
    case SerialLabel = 'serial_label';
    case Defect = 'defect';
    case ProofOfPurchase = 'proof_of_purchase';

    public function maximumCount(): int
    {
        return match ($this) {
            self::Product => (int) config('owned_products.uploads.max_product_images'),
            self::SerialLabel => (int) config('owned_products.uploads.max_serial_label_images'),
            self::Defect => (int) config('owned_products.uploads.max_defect_images'),
            self::ProofOfPurchase => (int) config('owned_products.uploads.max_proof_images'),
        };
    }
}
