<?php

namespace App\Actions\Listings;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateListing
{
    public function __construct(
        private readonly ListingAuthorizer $authorizer,
        private readonly RecordListingSnapshot $snapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Listing $listing, User $actor, array $attributes): Listing
    {
        return DB::transaction(function () use ($listing, $actor, $attributes): Listing {
            $lockedListing = Listing::query()
                ->forOrganization($listing->organization_id)
                ->lockForUpdate()
                ->findOrFail($listing->getKey());

            $this->authorizer->authorize(
                $lockedListing->organization,
                $actor,
                OrganizationPermission::ManageListings,
                lockForUpdate: true,
            );

            if (array_key_exists('marketplace_name', $attributes)) {
                $attributes['marketplace_key'] = CreateListing::marketplaceKey(
                    $attributes['marketplace_name'],
                );
            }

            $lockedListing->fill($attributes);
            $lockedListing->save();

            $this->snapshots->record($lockedListing, $actor, [
                'event' => 'manual_update',
                'input' => $attributes,
            ]);

            return $lockedListing;
        }, attempts: 3);
    }
}
