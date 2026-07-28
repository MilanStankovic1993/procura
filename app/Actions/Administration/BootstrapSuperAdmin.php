<?php

namespace App\Actions\Administration;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class BootstrapSuperAdmin
{
    public function __construct(
        private readonly RecordPlatformAuditEvent $recordAuditEvent,
    ) {}

    public function execute(User $user, string $reason): PlatformAuditEvent
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException('The operational reason must contain at least 10 characters.');
        }

        return DB::transaction(function () use ($user, $reason): PlatformAuditEvent {
            if (User::query()->where('is_super_admin', true)->lockForUpdate()->exists()) {
                throw new LogicException('A super administrator already exists. Bootstrap is no longer available.');
            }

            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! $lockedUser->hasVerifiedEmail()) {
                throw new LogicException('The first super administrator must have a verified email address.');
            }

            $lockedUser->forceFill(['is_super_admin' => true])->save();

            return $this->recordAuditEvent->record(
                actor: $lockedUser,
                action: 'user.super_admin_bootstrapped',
                subject: $lockedUser,
                reason: $reason,
                oldValues: ['is_super_admin' => false],
                newValues: ['is_super_admin' => true],
                userAgent: 'artisan:admin:bootstrap-super-admin',
            );
        }, 3);
    }
}
