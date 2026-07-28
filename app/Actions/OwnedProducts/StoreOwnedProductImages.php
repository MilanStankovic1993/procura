<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Organizations\OrganizationPermission;
use App\Enums\OwnedProducts\OwnedProductImageKind;
use App\Enums\OwnedProducts\OwnedProductStatus;
use App\Models\OwnedProduct;
use App\Models\OwnedProductImage;
use App\Models\User;
use App\Support\Uploads\ImageUploadInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StoreOwnedProductImages
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly ImageUploadInspector $imageInspector,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return list<OwnedProductImage>
     */
    public function store(
        OwnedProduct $ownedProduct,
        User $actor,
        OwnedProductImageKind $kind,
        array $files,
    ): array {
        $storedPaths = [];
        $disk = (string) config('owned_products.uploads.disk');

        try {
            return DB::transaction(function () use (
                $ownedProduct,
                $actor,
                $kind,
                $files,
                $disk,
                &$storedPaths,
            ): array {
                $locked = OwnedProduct::query()
                    ->forOrganization($ownedProduct->organization_id)
                    ->lockForUpdate()
                    ->findOrFail($ownedProduct->getKey());

                $this->authorizer->authorize(
                    $locked->organization,
                    $actor,
                    OrganizationPermission::ManageOwnedProducts,
                    lockForUpdate: true,
                );

                if ($locked->status === OwnedProductStatus::Archived) {
                    throw ValidationException::withMessages([
                        'status' => 'Archived owned products cannot receive images.',
                    ]);
                }

                $existingCount = $locked->images()
                    ->where('kind', $kind->value)
                    ->count();

                if ($existingCount + count($files) > $kind->maximumCount()) {
                    throw ValidationException::withMessages([
                        'images' => sprintf(
                            'An owned product may contain at most %d %s images.',
                            $kind->maximumCount(),
                            $kind->value,
                        ),
                    ]);
                }

                $position = (int) $locked->images()
                    ->where('kind', $kind->value)
                    ->max('position');
                $images = [];

                foreach ($files as $index => $file) {
                    $metadata = $this->imageInspector->inspect(
                        $file,
                        "images.{$index}",
                    );

                    if ($locked->images()
                        ->where('kind', $kind->value)
                        ->where('checksum_sha256', $metadata['checksum_sha256'])
                        ->exists()) {
                        throw ValidationException::withMessages([
                            "images.{$index}" => 'This image has already been uploaded.',
                        ]);
                    }

                    $imageId = (string) Str::ulid();
                    $directory = sprintf(
                        'organizations/%s/owned-products/%s/images',
                        $locked->organization_id,
                        $locked->getKey(),
                    );
                    $filename = "{$imageId}.{$metadata['extension']}";
                    $path = Storage::disk($disk)->putFileAs(
                        $directory,
                        $file,
                        $filename,
                    );

                    if (! is_string($path)) {
                        throw new RuntimeException(
                            'The image could not be written to private storage.',
                        );
                    }

                    $storedPaths[] = $path;
                    $position++;
                    $images[] = OwnedProductImage::query()->create([
                        'id' => $imageId,
                        'owned_product_id' => $locked->getKey(),
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
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk($disk)->delete($storedPaths);
            }

            throw $exception;
        }
    }
}
