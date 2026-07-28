<?php

namespace App\Support\Localization;

use App\Enums\Localization\SupportedLocale;
use Illuminate\Http\Request;

final class RequestLocaleResolver
{
    public const REQUEST_ATTRIBUTE = 'procura.request_locale';

    public function resolve(Request $request): SupportedLocale
    {
        $authenticatedLocale = $request->user()?->preferred_locale;

        if ($authenticatedLocale instanceof SupportedLocale) {
            return $authenticatedLocale;
        }

        foreach ($request->getLanguages() as $candidate) {
            $normalized = strtolower(str_replace('_', '-', $candidate));

            if (
                $normalized === 'sr'
                || str_starts_with($normalized, 'sr-')
            ) {
                return SupportedLocale::SerbianLatin;
            }

            foreach (SupportedLocale::cases() as $locale) {
                $supported = strtolower($locale->value);

                if (
                    $normalized === $supported
                    || str_starts_with($normalized, "{$supported}-")
                ) {
                    return $locale;
                }
            }
        }

        return SupportedLocale::English;
    }
}
