<?php

namespace App\Models;

use App\Enums\Catalog\ProductMatchReviewDecision;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ProductMatchReviewEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'analysis_id',
        'product_match_id',
        'result_product_match_id',
        'actor_user_id',
        'decision',
        'product_model_id',
        'product_variant_id',
        'product_alias_id',
        'reason',
        'idempotency_key',
        'payload_hash',
        'reviewed_at',
    ];

    protected $hidden = [
        'idempotency_key',
        'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'decision' => ProductMatchReviewDecision::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Product match review events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException(
                'Product match review events cannot be deleted individually.',
            );
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function productMatch(): BelongsTo
    {
        return $this->belongsTo(ProductMatch::class);
    }

    public function resultProductMatch(): BelongsTo
    {
        return $this->belongsTo(ProductMatch::class, 'result_product_match_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function productAlias(): BelongsTo
    {
        return $this->belongsTo(ProductAlias::class);
    }
}
