<?php

namespace App\Http\Resources\V1;

use App\Models\ListingImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin ListingImage */
class ListingImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'kind' => $this->kind->value,
            'filename' => $this->client_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'position' => $this->position,
            'content_url' => URL::temporarySignedRoute(
                'api.v1.listing-images.content',
                now()->addMinutes((int) config('listings.uploads.temporary_url_minutes')),
                ['image' => $this->getKey()],
                absolute: false,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
