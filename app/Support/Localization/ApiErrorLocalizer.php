<?php

namespace App\Support\Localization;

use App\Enums\Api\ApiErrorCode;
use App\Enums\Localization\SupportedLocale;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;

final readonly class ApiErrorLocalizer
{
    public function __construct(
        private Translator $translator,
    ) {}

    public function message(
        ApiErrorCode $errorCode,
        Request $request,
    ): string {
        $locale = $this->requestLocale($request);

        return $this->translator->get(
            "api_errors.{$errorCode->value}",
            locale: $locale->laravelLocale(),
        );
    }

    private function requestLocale(Request $request): SupportedLocale
    {
        $locale = $request->attributes->get(
            RequestLocaleResolver::REQUEST_ATTRIBUTE,
        );

        return is_string($locale)
            ? SupportedLocale::tryFrom($locale)
                ?? SupportedLocale::English
            : SupportedLocale::English;
    }
}
