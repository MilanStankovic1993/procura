<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellListingDraftProjectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'assessment_current' => $this->resource['assessment_current'],
            'current_assessment_id' => (
                $this->resource['current_assessment_id']
            ),
            'available_price_bands' => SellPriceBandResource::collection(
                $this->resource['available_price_bands'],
            ),
            'drafts' => SellListingDraftResource::collection(
                $this->resource['drafts'],
            ),
            'current_drafts' => SellListingDraftResource::collection(
                $this->resource['current_drafts'],
            ),
        ];
    }
}
