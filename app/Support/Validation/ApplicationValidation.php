<?php

namespace App\Support\Validation;

use App\Enums\Validation\ApplicationValidationCode;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ApplicationValidation
{
    /**
     * @param  array<string, int|string>  $replace
     */
    public static function fail(
        string $field,
        ApplicationValidationCode $code,
        array $replace = [],
    ): never {
        throw ValidationException::withMessages([
            $field => [self::message($code, $replace)],
        ]);
    }

    /**
     * @param  array<string, ApplicationValidationCode>  $errors
     */
    public static function failMany(array $errors): never
    {
        $messages = [];

        foreach ($errors as $field => $code) {
            $messages[$field] = [self::message($code)];
        }

        throw ValidationException::withMessages($messages);
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    public static function message(
        ApplicationValidationCode $code,
        array $replace = [],
    ): string {
        $message = Lang::get(
            "application_validation.{$code->value}",
            $replace,
        );

        if (! is_string($message)) {
            throw new LogicException(
                "Application validation message [{$code->value}] is not a string.",
            );
        }

        return $message;
    }
}
