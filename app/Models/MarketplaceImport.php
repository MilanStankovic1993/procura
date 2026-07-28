<?php

namespace App\Models;

use App\Enums\Listings\MarketplaceImportStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceImport extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'marketplace_source_id',
        'created_by_user_id',
        'idempotency_key',
        'status',
        'schema_version',
        'delimiter',
        'default_target_country_code',
        'disk',
        'path',
        'original_file_name',
        'mime_type',
        'size_bytes',
        'content_hash',
        'authorization_confirmed_at',
        'total_rows',
        'processed_rows',
        'imported_rows',
        'rejected_rows',
        'duplicate_rows',
        'processing_attempts',
        'started_at',
        'completed_at',
        'failed_at',
        'last_error_code',
        'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => MarketplaceImportStatus::class,
            'schema_version' => 'integer',
            'size_bytes' => 'integer',
            'authorization_confirmed_at' => 'immutable_datetime',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'imported_rows' => 'integer',
            'rejected_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'processing_attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function marketplaceSource(): BelongsTo
    {
        return $this->belongsTo(MarketplaceSource::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(MarketplaceImportRow::class)->orderBy('row_number');
    }
}
