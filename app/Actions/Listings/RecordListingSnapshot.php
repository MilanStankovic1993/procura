<?php

namespace App\Actions\Listings;

use App\Jobs\Monitoring\MatchListingSnapshot;
use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordListingSnapshot
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function record(
        Listing $listing,
        User $actor,
        array $rawPayload,
    ): ListingSnapshot {
        $sequence = ((int) $listing->snapshots()->max('sequence')) + 1;
        $facts = [
            'source_url' => $listing->source_url,
            'external_id' => $listing->external_id,
            'marketplace_name' => $listing->marketplace_name,
            'marketplace_key' => $listing->marketplace_key,
            'title' => $listing->title,
            'description' => $listing->description,
            'asking_price_minor' => $listing->asking_price_minor,
            'currency_code' => $listing->currency_code,
            'seller_information' => $listing->seller_information,
            'location' => $listing->location,
            'source_country_code' => $listing->source_country_code,
            'target_country_code' => $listing->target_country_code,
            'status' => $listing->status->value,
            'notes' => $listing->notes,
        ];
        $encodedFacts = json_encode(
            $facts,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $snapshot = $listing->snapshots()->create([
            ...$facts,
            'sequence' => $sequence,
            'captured_by_user_id' => $actor->getKey(),
            'captured_at' => now(),
            'raw_payload' => $rawPayload,
            'content_hash' => hash('sha256', $encodedFacts),
        ]);

        DB::afterCommit(
            static fn () => MatchListingSnapshot::dispatch(
                $snapshot->getKey(),
            ),
        );

        return $snapshot;
    }
}
