<?php

namespace App\Models;

use App\Enums\Privacy\PrivacyRequestType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PrivacyRequestFulfillment extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'privacy_request_id',
        'completion_event_id',
        'actor_user_id',
        'request_type',
        'execution_version',
        'data_inventory_version',
        'identity_evidence_reference',
        'artifact_reference',
        'artifact_sha256',
        'artifact_size_bytes',
        'artifact_expires_at',
        'delivery_evidence_reference',
        'erasure_evidence_reference',
        'storage_evidence_reference',
        'processor_evidence_reference',
        'backup_purge_due_at',
        'clearance_references',
        'idempotency_key',
        'payload_hash',
        'completed_at',
    ];

    protected $hidden = [
        'identity_evidence_reference',
        'artifact_reference',
        'artifact_sha256',
        'delivery_evidence_reference',
        'erasure_evidence_reference',
        'storage_evidence_reference',
        'processor_evidence_reference',
        'clearance_references',
        'idempotency_key',
        'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'request_type' => PrivacyRequestType::class,
            'artifact_size_bytes' => 'integer',
            'artifact_expires_at' => 'immutable_datetime',
            'backup_purge_due_at' => 'immutable_datetime',
            'clearance_references' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException(
                'Privacy-request fulfillment receipts are immutable.',
            );
        });
        self::deleting(static function (): never {
            throw new LogicException(
                'Privacy-request fulfillment receipts cannot be deleted individually.',
            );
        });
    }

    public function privacyRequest(): BelongsTo
    {
        return $this->belongsTo(PrivacyRequest::class);
    }

    public function completionEvent(): BelongsTo
    {
        return $this->belongsTo(
            PrivacyRequestEvent::class,
            'completion_event_id',
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
