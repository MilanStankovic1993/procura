<?php

namespace App\Policies;

use App\Models\PrivacyRequest;
use App\Models\User;

final class PrivacyRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_super_admin && $user->hasVerifiedEmail();
    }

    public function view(
        User $user,
        PrivacyRequest $privacyRequest,
    ): bool {
        return (
            $user->is_super_admin
            && $user->hasVerifiedEmail()
        ) || $privacyRequest->subject_user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function cancel(
        User $user,
        PrivacyRequest $privacyRequest,
    ): bool {
        return $privacyRequest->subject_user_id === $user->getKey();
    }
}
