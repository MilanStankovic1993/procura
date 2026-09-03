<?php

namespace App\Http\Resources\V1;

use App\Models\SavedSearchVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SavedSearchVersion */
class SavedSearchVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'previous_version_id' => $this->previous_version_id,
            'sequence' => $this->sequence,
            'title' => $this->title,
            'active' => $this->active,
            'criteria' => [
                'product_category_id' => $this->product_category_id,
                'brand_id' => $this->brand_id,
                'product_model_id' => $this->product_model_id,
                'minimum_price_minor' => $this->minimum_price_minor,
                'maximum_price_minor' => $this->maximum_price_minor,
                'price_currency_code' => $this->price_currency_code,
                'continent_code' => $this->continent_code?->value,
                'country_codes' => $this->country_codes,
                'city' => $this->city,
                'radius_km' => $this->radius_km,
                'include_cross_border' => $this->include_cross_border,
                'required_keywords' => $this->required_keywords,
                'excluded_keywords' => $this->excluded_keywords,
                'minimum_profit_minor' => $this->minimum_profit_minor,
                'profit_currency_code' => $this->profit_currency_code,
                'minimum_margin_basis_points' => (
                    $this->minimum_margin_basis_points
                ),
                'minimum_deal_score_basis_points' => (
                    $this->minimum_deal_score_basis_points
                ),
                'maximum_risk_score' => $this->maximum_risk_score,
            ],
            'catalog' => [
                'category' => $this->whenLoaded(
                    'category',
                    fn (): ?array => $this->category === null
                        ? null
                        : [
                            'id' => $this->category->getKey(),
                            'name' => $this->category->name,
                        ],
                ),
                'brand' => $this->whenLoaded(
                    'brand',
                    fn (): ?array => $this->brand === null
                        ? null
                        : [
                            'id' => $this->brand->getKey(),
                            'name' => $this->brand->name,
                        ],
                ),
                'model' => $this->whenLoaded(
                    'productModel',
                    fn (): ?array => $this->productModel === null
                        ? null
                        : [
                            'id' => $this->productModel->getKey(),
                            'name' => $this->productModel->name,
                            'model_number' => $this->productModel->model_number,
                        ],
                ),
            ],
            'notification_channels' => $this->notification_channels,
            'reason_code' => $this->reason_code,
            'criteria_hash' => $this->criteria_hash,
            'changed_by_user_id' => $this->changed_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
