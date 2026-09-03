<?php

namespace App\Catalog;

use Illuminate\Support\Str;

final class CatalogTextNormalizer
{
    public static function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    /**
     * @return list<string>
     */
    public static function ngrams(
        string $value,
        int $maxTokens,
        int $maxNgramTokens,
        int $limit,
    ): array {
        $normalized = self::normalize($value);

        if ($normalized === '') {
            return [];
        }

        $tokens = array_slice(explode(' ', $normalized), 0, max(1, $maxTokens));
        $terms = [];
        $tokenCount = count($tokens);
        $largestNgram = min(max(1, $maxNgramTokens), $tokenCount);

        for ($size = $largestNgram; $size >= 1; $size--) {
            for ($start = 0; $start <= $tokenCount - $size; $start++) {
                $term = implode(' ', array_slice($tokens, $start, $size));

                if (strlen($term) < 2) {
                    continue;
                }

                $terms[$term] = true;

                if (count($terms) >= max(1, $limit)) {
                    return array_keys($terms);
                }
            }
        }

        return array_keys($terms);
    }
}
