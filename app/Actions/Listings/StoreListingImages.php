<?php

namespace App\Actions\Listings;

use App\Enums\Listings\ListingImageKind;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\User;
use App\Support\Uploads\ImageUploadInspector;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StoreListingImages
{
    public function __construct(
        private readonly ListingAuthorizer $authorizer,
        private readonly ImageUploadInspector $imageInspector,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return list<ListingImage>
     */
    public function store(
        Listing $listing,
        User $actor,
        ListingImageKind $kind,
        array $files,
    ): array {
        $storedPaths = [];
        $disk = (string) config('listings.uploads.disk');

        try {
            return DB::transaction(function () use (
                $listing,
                $actor,
                $kind,
                $files,
                $disk,
                &$storedPaths,
            ): array {
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

                $existingCount = $lockedListing->images()
                    ->where('kind', $kind->value)
                    ->count();

                if ($existingCount + count($files) > $kind->maximumCount()) {
                    ApplicationValidation::fail(
                        'images',
                        ApplicationValidationCode::ListingImageLimit,
                        ['max' => $kind->maximumCount()],
                    );
                }

                $position = (int) $lockedListing->images()
                    ->where('kind', $kind->value)
                    ->max('position');
                $images = [];

                foreach ($files as $index => $file) {
                    $metadata = $this->imageInspector->inspect(
                        $file,
                        "images.{$index}",
                    );

                    if ($lockedListing->images()
                        ->where('kind', $kind->value)
                        ->where('checksum_sha256', $metadata['checksum_sha256'])
                        ->exists()) {
                        ApplicationValidation::fail(
                            "images.{$index}",
                            ApplicationValidationCode::DuplicateImage,
                        );
                    }

                    $imageId = (string) Str::ulid();
                    $directory = sprintf(
                        'organizations/%s/listings/%s/images',
                        $lockedListing->organization_id,
                        $lockedListing->getKey(),
                    );
                    $filename = "{$imageId}.{$metadata['extension']}";
                    $path = Storage::disk($disk)->putFileAs($directory, $file, $filename);

                    if (! is_string($path)) {
                        throw new RuntimeException('The image could not be written to private storage.');
                    }

                    $storedPaths[] = $path;
                    $position++;
                    $images[] = ListingImage::query()->create([
                        'id' => $imageId,
                        'listing_id' => $lockedListing->getKey(),
                        'uploaded_by_user_id' => $actor->getKey(),
                        'kind' => $kind,
                        'disk' => $disk,
                        'path' => $path,
                        'client_filename' => $metadata['client_filename'],
                        'mime_type' => $metadata['mime_type'],
                        'extension' => $metadata['extension'],
                        'size_bytes' => $file->getSize(),
                        'width' => $metadata['width'],
                        'height' => $metadata['height'],
                        'checksum_sha256' => $metadata['checksum_sha256'],
                        'position' => $position,
                    ]);
                }

                return $images;
            });
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk($disk)->delete($storedPaths);
            }

            throw $exception;
        }
    }
}
