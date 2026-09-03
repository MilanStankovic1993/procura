<?php

namespace App\MarketplaceConnectors\Connectors;

use App\MarketplaceConnectors\Contracts\MarketplaceConnector;
use App\MarketplaceConnectors\Data\MarketplaceListingData;
use App\Models\MarketplaceImport;
use App\Models\MarketplaceSource;

final class AuthorizedCsvConnector implements MarketplaceConnector
{
    public function key(): string
    {
        return MarketplaceSource::AUTHORIZED_CSV_KEY;
    }

    public function capabilities(): array
    {
        return ['import'];
    }

    public function supportsAutomatedSearch(): bool
    {
        return false;
    }

    public function normalize(
        array $payload,
        MarketplaceImport $import,
    ): MarketplaceListingData {
        $normalized = [];

        foreach ([
            'source_url',
            'external_id',
            'marketplace_name',
            'title',
            'description',
            'asking_price_minor',
            'currency_code',
            'seller_information',
            'location',
            'source_country_code',
            'target_country_code',
            'status',
            'notes',
        ] as $field) {
            $value = $payload[$field] ?? null;
            $normalized[$field] = is_string($value) ? trim($value) : $value;

            if ($normalized[$field] === '') {
                $normalized[$field] = null;
            }
        }

        foreach (['currency_code', 'source_country_code', 'target_country_code'] as $field) {
            if (is_string($normalized[$field])) {
                $normalized[$field] = mb_strtoupper($normalized[$field]);
            }
        }

        if ($normalized['target_country_code'] === null) {
            $normalized['target_country_code'] = $import->default_target_country_code;
        }

        $normalized['status'] = is_string($normalized['status'])
            ? mb_strtolower($normalized['status'])
            : 'unknown';

        if (
            is_string($normalized['asking_price_minor'])
            && preg_match('/^\d+$/', $normalized['asking_price_minor']) === 1
        ) {
            $normalized['asking_price_minor'] = (int) $normalized['asking_price_minor'];
        }

        return new MarketplaceListingData($normalized);
    }
}
