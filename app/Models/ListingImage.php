<?php

namespace App\Models;

use App\Enums\Listings\ListingImageKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingImage extends Model
{
    use HasUlids;

    protected $fillable = [
        'listing_id',
        'uploaded_by_user_id',
        'kind',
        'disk',
        'path',
        'client_filename',
        'mime_type',
        'extension',
        'size_bytes',
        'width',
        'height',
        'checksum_sha256',
        'position',
    ];

    protected $hidden = [
        'disk',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ListingImageKind::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
