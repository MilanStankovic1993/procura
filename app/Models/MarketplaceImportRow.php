<?php

namespace App\Models;

use App\Enums\Listings\MarketplaceImportRowStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceImportRow extends Model
{
    use HasUlids;

    protected $fillable = [
        'marketplace_import_id',
        'row_number',
        'status',
        'listing_id',
        'raw_payload',
        'normalized_payload',
        'validation_errors',
        'row_hash',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'status' => MarketplaceImportRowStatus::class,
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
            'validation_errors' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function marketplaceImport(): BelongsTo
    {
        return $this->belongsTo(MarketplaceImport::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
