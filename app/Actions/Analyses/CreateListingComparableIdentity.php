<?php

namespace App\Actions\Analyses;

use App\Enums\Validation\ApplicationValidationCode;
use App\Support\Validation\ApplicationValidation;

final class CreateListingComparableIdentity
{
    public static function marketplaceKey(string $marketplaceName): string
    {
        $key = str($marketplaceName)->lower()->slug()->limit(80, '')->toString();

        return $key !== ''
            ? $key
            : 'marketplace-'.substr(hash('sha256', $marketplaceName), 0, 24);
    }

    public static function sourceIdentityHash(
        string $organizationId,
        string $sourceId,
        string $marketplaceKey,
        ?string $externalId,
        ?string $sourceUrl,
    ): string {
        $identity = $externalId !== null
            ? 'external:'.mb_strtolower(trim($externalId))
            : 'url:'.self::normalizedUrl($sourceUrl);

        return hash('sha256', implode('|', [
            $organizationId,
            $sourceId,
            $marketplaceKey,
            $identity,
        ]));
    }

    private static function normalizedUrl(?string $url): string
    {
        if ($url === null) {
            ApplicationValidation::fail(
                'source_url',
                ApplicationValidationCode::ComparableIdentityRequired,
            );
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            ApplicationValidation::fail(
                'source_url',
                ApplicationValidationCode::ComparableSourceUrlInvalid,
            );
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port'])
            && ! (
                ($scheme === 'http' && $parts['port'] === 80)
                || ($scheme === 'https' && $parts['port'] === 443)
            )
                ? ':'.$parts['port']
                : '';
        $path = $parts['path'] ?? '/';
        $query = '';

        if (isset($parts['query'])) {
            parse_str($parts['query'], $parameters);
            ksort($parameters);
            $queryString = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
            $query = $queryString === '' ? '' : '?'.$queryString;
        }

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }
}
