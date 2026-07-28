<?php

namespace App\Filament\Support;

use BackedEnum;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;
use Throwable;

final class AdminLabel
{
    public static function value(string $group, BackedEnum|string|null $state): string
    {
        $value = $state instanceof BackedEnum ? $state->value : (string) $state;
        $translations = __("admin.values.{$group}");

        if (
            is_array($translations)
            && array_key_exists($value, $translations)
            && is_string($translations[$value])
        ) {
            return $translations[$value];
        }

        return str($value)->replace(['.', '_'], ' ')->headline()->toString();
    }

    public static function country(string $code, string $fallback): string
    {
        try {
            return Countries::getName($code, app()->getLocale());
        } catch (Throwable) {
            return $fallback;
        }
    }

    public static function currency(string $code, string $fallback): string
    {
        try {
            return Currencies::getName($code, app()->getLocale());
        } catch (Throwable) {
            return $fallback;
        }
    }

    public static function subject(string $state): string
    {
        return self::value('subject', str(class_basename($state))->snake()->toString());
    }
}
