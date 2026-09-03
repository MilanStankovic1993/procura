<?php

namespace App\Http\Controllers\Api\V1\Listings;

use App\Http\Controllers\Controller;
use App\Models\ListingImage;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShowListingImageController extends Controller
{
    public function __invoke(
        string $image,
        OrganizationContext $context,
    ): StreamedResponse {
        $record = ListingImage::query()
            ->whereHas(
                'listing',
                static fn ($query) => $query->forOrganization($context->organization()),
            )
            ->with('listing')
            ->findOrFail($image);

        Gate::authorize('view', $record->listing);
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
