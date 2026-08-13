<?php

namespace App\Models;

use App\Enums\Catalog\CatalogImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogImport extends Model
{
    use HasUlids;

    protected $fillable = [
        'created_by_user_id',
        'source_name',
        'source_key',
        'source_url',
        'license_name',
        'dataset_version',
        'notes',
        'rights_confirmed_at',
        'status',
        'schema_version',
        'delimiter',
        'disk',
        'path',
        'original_file_name',
        'mime_type',
        'size_bytes',
        'content_hash',
        'total_rows',
        'processed_rows',
        'imported_rows',
        'unchanged_rows',
        'rejected_rows',
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
            'status' => CatalogImportStatus::class,
            'rights_confirmed_at' => 'immutable_datetime',
            'schema_version' => 'integer',
            'size_bytes' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'imported_rows' => 'integer',
            'unchanged_rows' => 'integer',
            'rejected_rows' => 'integer',
            'processing_attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CatalogImportRow::class)->orderBy('row_number');
    }
}
