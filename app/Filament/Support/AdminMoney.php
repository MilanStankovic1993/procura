<?php

namespace App\Filament\Support;

final class AdminMoney
{
    public static function minor(
        ?int $amount,
        ?string $currencyCode,
        ?int $minorUnit = 2,
    ): ?string {
        if ($amount === null) {
            return null;
        }

        $digits = max(0, min($minorUnit ?? 2, 4));
        $formatted = number_format(
            $amount / (10 ** $digits),
            $digits,
            '.',
            ',',
        );

        return filled($currencyCode)
            ? sprintf('%s %s', $formatted, $currencyCode)
            : $formatted;
    }
}
