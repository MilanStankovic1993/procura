<?php

namespace App\Models;

use App\Enums\Listings\ListingStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'marketplace_source_id',
        'created_by_user_id',
        'source_url',
        'external_id',
        'marketplace_name',
        'marketplace_key',
        'title',
        'description',
        'asking_price_minor',
        'currency_code',
        'seller_information',
        'location',
        'source_country_code',
        'target_country_code',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'asking_price_minor' => 'integer',
            'status' => ListingStatus::class,
            'raw_input' => 'array',
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

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code');
    }

    public function sourceCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'source_country_code');
    }

    public function targetCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'target_country_code');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ListingImage::class)
            ->orderBy('kind')
            ->orderBy('position');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ListingSnapshot::class)->orderByDesc('sequence');
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class)->orderByDesc('created_at');
    }
}
