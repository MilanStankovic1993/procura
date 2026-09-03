<?php

namespace App\Actions\OwnedProducts;

use App\Models\OwnedProduct;
use App\Models\OwnedProductSnapshot;
use App\Models\User;

class RecordOwnedProductSnapshot
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function record(
        OwnedProduct $ownedProduct,
        User $actor,
        array $rawPayload,
    ): OwnedProductSnapshot {
        $ownedProduct->loadMissing('targetCountries');
        $sequence = ((int) $ownedProduct->snapshots()->max('sequence')) + 1;
        $facts = [
            'product_category_id' => $ownedProduct->product_category_id,
            'brand_name' => $ownedProduct->brand_name,
            'model_name' => $ownedProduct->model_name,
            'condition' => $ownedProduct->condition->value,
            'age_months' => $ownedProduct->age_months,
            'accessories' => $ownedProduct->accessories,
            'defects' => $ownedProduct->defects,
            'purchase_history_known' => $ownedProduct->purchase_history_known,
            'purchase_history' => $ownedProduct->purchase_history,
            'target_continent_code' => $ownedProduct->target_continent_code,
            'target_country_codes' => $ownedProduct->targetCountries
                ->pluck('code')
                ->values()
                ->all(),
            'cross_border_preference' => $ownedProduct->cross_border_preference->value,
            'desired_sale_speed' => $ownedProduct->desired_sale_speed->value,
            'status' => $ownedProduct->status->value,
            'notes' => $ownedProduct->notes,
        ];
        $encodedFacts = json_encode(
            $facts,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $ownedProduct->snapshots()->create([
            ...$facts,
            'sequence' => $sequence,
            'captured_by_user_id' => $actor->getKey(),
            'captured_at' => now(),
            'raw_payload' => $rawPayload,
            'content_hash' => hash('sha256', $encodedFacts),
        ]);
    }
}
