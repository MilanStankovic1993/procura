<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellPriceIntelligenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'assessment_current' => $this->resource['assessment_current'],
            'current_assessment_id' => (
                $this->resource['current_assessment_id']
            ),
            'records' => SellComparableRecordResource::collection(
                $this->resource['records'],
            ),
            'selections' => SellComparableSelectionResource::collection(
                $this->resource['selections'],
            ),
            'price_bands' => SellPriceBandResource::collection(
                $this->resource['price_bands'],
            ),
            'current_price_bands' => SellPriceBandResource::collection(
                $this->resource['current_price_bands'],
            ),
        ];
    }
}
