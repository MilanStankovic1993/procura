<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Organizations\OrganizationPermission;
use App\Enums\OwnedProducts\OwnedProductStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\OwnedProductImage;
use App\Models\User;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteOwnedProductImage
{
    public function __construct(private readonly OwnedProductAuthorizer $authorizer) {}

    public function delete(OwnedProductImage $image, User $actor): void
    {
        DB::transaction(function () use ($image, $actor): void {
            $lockedImage = OwnedProductImage::query()
                ->with('ownedProduct.organization')
                ->lockForUpdate()
                ->findOrFail($image->getKey());

            $this->authorizer->authorize(
                $lockedImage->ownedProduct->organization,
                $actor,
                OrganizationPermission::ManageOwnedProducts,
                lockForUpdate: true,
            );

            if ($lockedImage->ownedProduct->status === OwnedProductStatus::Archived) {
                ApplicationValidation::fail(
                    'status',
                    ApplicationValidationCode::ArchivedOwnedProductImmutable,
                );
            }

            $disk = $lockedImage->disk;
            $path = $lockedImage->path;
            $lockedImage->delete();

            DB::afterCommit(static function () use ($disk, $path): void {
                Storage::disk($disk)->delete($path);
            });
        }, attempts: 3);
    }
}
