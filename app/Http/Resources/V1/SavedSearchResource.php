<?php

namespace App\Http\Resources\V1;

use App\Models\SavedSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SavedSearch */
class SavedSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'owner_user_id' => $this->owner_user_id,
            'title' => $this->title,
            'active' => $this->active,
            'archived' => $this->archived_at !== null,
            'current_version_id' => $this->current_version_id,
            'version_sequence' => $this->version_sequence,
            'current_version' => new SavedSearchVersionResource(
                $this->whenLoaded('currentVersion'),
            ),
            'versions' => SavedSearchVersionResource::collection(
                $this->whenLoaded('versions'),
            ),
            'version_count' => $this->whenCounted('versions'),
            'match_count' => $this->whenCounted('matches'),
            'matched_count' => $this->when(
                array_key_exists(
                    'matched_count',
                    $this->resource->getAttributes(),
                ),
                fn (): int => (int) $this->resource->getAttribute(
                    'matched_count',
                ),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
