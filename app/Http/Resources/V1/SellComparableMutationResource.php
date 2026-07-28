<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellComparableMutationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'record' => new SellComparableRecordResource(
                $this->resource['record'],
            ),
            'selection' => new SellComparableSelectionResource(
                $this->resource['selection'],
            ),
            'price_band' => new SellPriceBandResource(
                $this->resource['price_band'],
            ),
        ];
    }
}
