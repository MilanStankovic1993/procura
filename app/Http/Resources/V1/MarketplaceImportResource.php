<?php

namespace App\Http\Resources\V1;

use App\Models\MarketplaceImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MarketplaceImport */
final class MarketplaceImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'marketplace_source' => new MarketplaceSourceResource(
                $this->whenLoaded('marketplaceSource'),
            ),
            'created_by' => $this->whenLoaded(
                'createdBy',
                fn (): ?array => $this->createdBy === null ? null : [
                    'id' => $this->createdBy->getKey(),
                    'name' => $this->createdBy->name,
                ],
            ),
            'status' => $this->status->value,
            'schema_version' => $this->schema_version,
            'delimiter' => $this->delimiter,
            'default_target_country_code' => $this->default_target_country_code,
            'original_file_name' => $this->original_file_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'content_hash' => $this->content_hash,
            'authorization_confirmed_at' => $this
                ->authorization_confirmed_at
                ?->toIso8601String(),
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'imported_rows' => $this->imported_rows,
            'rejected_rows' => $this->rejected_rows,
            'duplicate_rows' => $this->duplicate_rows,
            'processing_attempts' => $this->processing_attempts,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'last_error_code' => $this->last_error_code,
            'last_error_message' => $this->last_error_message,
            'rows' => MarketplaceImportRowResource::collection(
                $this->whenLoaded('rows'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
