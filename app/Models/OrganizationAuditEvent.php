<?php

namespace App\Models;

use App\Enums\Organizations\OrganizationAuditEventType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationAuditEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'actor_user_id',
        'event',
        'subject_user_id',
        'subject_invitation_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event' => OrganizationAuditEventType::class,
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function subjectInvitation(): BelongsTo
    {
        return $this->belongsTo(OrganizationInvitation::class, 'subject_invitation_id');
    }
}
