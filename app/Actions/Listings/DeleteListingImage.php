<?php

namespace App\Actions\Listings;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\ListingImage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteListingImage
{
    public function __construct(private readonly ListingAuthorizer $authorizer) {}

    public function delete(ListingImage $image, User $actor): void
    {
        DB::transaction(function () use ($image, $actor): void {
            $lockedImage = ListingImage::query()
                ->with('listing.organization')
                ->lockForUpdate()
                ->findOrFail($image->getKey());

            $this->authorizer->authorize(
                $lockedImage->listing->organization,
                $actor,
                OrganizationPermission::ManageListings,
                lockForUpdate: true,
            );

            $disk = $lockedImage->disk;
            $path = $lockedImage->path;
            $lockedImage->delete();

            DB::afterCommit(static function () use ($disk, $path): void {
                Storage::disk($disk)->delete($path);
            });
        }, attempts: 3);
    }
}
