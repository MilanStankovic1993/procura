<?php

namespace App\Actions\Users;

use App\Enums\Localization\SupportedLocale;
use App\Models\User;

class UpdatePreferredLocale
{
    public function update(User $user, SupportedLocale $locale): User
    {
        if ($user->preferred_locale === $locale) {
            return $user;
        }

        $user->forceFill(['preferred_locale' => $locale])->save();

        return $user->refresh();
    }
}
