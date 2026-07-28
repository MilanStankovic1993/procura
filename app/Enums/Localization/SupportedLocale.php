<?php

namespace App\Enums\Localization;

enum SupportedLocale: string
{
    case English = 'en';
    case German = 'de';
    case Spanish = 'es';
    case French = 'fr';
    case SerbianLatin = 'sr-Latn';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $locale): string => $locale->value,
            self::cases(),
        );
    }

    /**
     * Filament's bundled Serbian translations follow Laravel's underscore locale convention.
     */
    public function laravelLocale(): string
    {
        return match ($this) {
            self::SerbianLatin => 'sr_Latn',
            default => $this->value,
        };
    }

    public function nativeLabel(): string
    {
        return match ($this) {
            self::English => 'English',
            self::German => 'Deutsch',
            self::Spanish => 'Español',
            self::French => 'Français',
            self::SerbianLatin => 'Srpski',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function nativeOptions(): array
    {
        $options = [];

        foreach (self::cases() as $locale) {
            $options[$locale->value] = $locale->nativeLabel();
        }

        return $options;
    }
}
