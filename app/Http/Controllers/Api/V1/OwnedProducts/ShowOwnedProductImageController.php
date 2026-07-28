<?php

namespace App\Http\Controllers\Api\V1\OwnedProducts;

use App\Http\Controllers\Controller;
use App\Models\OwnedProductImage;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShowOwnedProductImageController extends Controller
{
    public function __invoke(
        string $image,
        OrganizationContext $context,
    ): StreamedResponse {
        $record = OwnedProductImage::query()
            ->whereHas(
                'ownedProduct',
                static fn ($query) => $query->forOrganization($context->organization()),
            )
            ->with('ownedProduct')
            ->findOrFail($image);

        Gate::authorize('view', $record->ownedProduct);
        abort_unless(Storage::disk($record->disk)->exists($record->path), 404);

        return Storage::disk($record->disk)->response(
            $record->path,
            $record->client_filename,
            [
                'Content-Type' => $record->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
