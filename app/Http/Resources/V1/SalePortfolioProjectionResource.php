<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalePortfolioProjectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'assessment_current' => $this->resource['assessment_current'],
            'available_listing_drafts' => (
                SellListingDraftResource::collection(
                    $this->resource['available_listing_drafts'],
                )
            ),
            'entries' => SalePortfolioEntryResource::collection(
                $this->resource['entries'],
            ),
            'current_entry_id' => $this->resource['current_entry_id'],
        ];
    }
}
