<?php

namespace App\Models;

use App\Enums\Outcomes\OutcomeEvidenceKind;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class OutcomeEstimateAttribution extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id',
        'owned_product_id',
        'realized_profit_id',
        'analysis_id',
        'profit_estimate_id',
        'previous_attribution_id',
        'actor_user_id',
        'sequence',
        'reason_code',
        'evidence_kind',
        'evidence_reference',
        'correction_reason',
        'note',
        'idempotency_key',
        'payload_hash',
        'input_hash',
        'attribution_snapshot',
        'attributed_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'evidence_kind' => OutcomeEvidenceKind::class,
            'attribution_snapshot' => 'array',
            'attributed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException(
                'Outcome-to-estimate attributions are immutable.',
            );
        });
        static::deleting(static function (): never {
            throw new LogicException(
                'Outcome-to-estimate attributions cannot be deleted individually.',
            );
        });
    }

    public function ownedProduct(): BelongsTo
    {
        return $this->belongsTo(OwnedProduct::class);
    }

    public function realizedProfit(): BelongsTo
    {
        return $this->belongsTo(RealizedProfit::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function profitEstimate(): BelongsTo
    {
        return $this->belongsTo(ProfitEstimate::class);
    }

    public function previousAttribution(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_attribution_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function accuracyReport(): HasOne
    {
        return $this->hasOne(
            EstimateAccuracyReport::class,
            'outcome_estimate_attribution_id',
        );
    }
}
