<?php

namespace App\Actions\Listings;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\Listing;
use App\Models\MarketplaceSource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateListing
{
    public function __construct(
        private readonly ListingAuthorizer $authorizer,
        private readonly RecordListingSnapshot $snapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        Organization $organization,
        User $actor,
        MarketplaceSource $source,
        array $attributes,
        array $evidence = [],
    ): Listing {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $source,
            $attributes,
            $evidence,
        ): Listing {
            $this->authorizer->authorize(
                $organization,
                $actor,
                OrganizationPermission::ManageListings,
                lockForUpdate: true,
            );

            $listing = Listing::query()->forceCreate([
                ...$attributes,
                'organization_id' => $organization->getKey(),
                'marketplace_source_id' => $source->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'marketplace_key' => self::marketplaceKey($attributes['marketplace_name']),
                'raw_input' => $attributes,
            ]);

            $this->snapshots->record($listing, $actor, [
                'event' => $evidence['event'] ?? 'manual_creation',
                'input' => $attributes,
                ...$evidence,
            ]);

            return $listing;
        }, attempts: 3);
    }

    public static function marketplaceKey(string $marketplaceName): string
    {
        $key = str($marketplaceName)->lower()->slug()->limit(80, '')->toString();

        return $key !== ''
            ? $key
            : 'marketplace-'.substr(hash('sha256', $marketplaceName), 0, 24);
    }
}
