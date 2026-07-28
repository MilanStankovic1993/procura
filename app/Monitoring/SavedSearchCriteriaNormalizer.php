<?php

namespace App\Monitoring;

final class SavedSearchCriteriaNormalizer
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $countryCodes = $this->normalizedList(
            $input['country_codes'] ?? [],
            uppercase: true,
        );
        $requiredKeywords = $this->normalizedList(
            $input['required_keywords'] ?? [],
        );
        $excludedKeywords = $this->normalizedList(
            $input['excluded_keywords'] ?? [],
        );
        $channels = $this->normalizedList(
            $input['notification_channels'] ?? ['in_app'],
        );

        return [
            'title' => trim((string) $input['title']),
            'active' => (bool) ($input['active'] ?? true),
            'product_category_id' => $input['product_category_id'] ?? null,
            'brand_id' => $input['brand_id'] ?? null,
            'product_model_id' => $input['product_model_id'] ?? null,
            'minimum_price_minor' => $input['minimum_price_minor'] ?? null,
            'maximum_price_minor' => $input['maximum_price_minor'] ?? null,
            'price_currency_code' => isset($input['price_currency_code'])
                ? mb_strtoupper((string) $input['price_currency_code'])
                : null,
            'continent_code' => isset($input['continent_code'])
                ? mb_strtoupper((string) $input['continent_code'])
                : null,
            'country_codes' => $countryCodes,
            'city' => $this->nullableTrimmed($input['city'] ?? null),
            'radius_km' => $input['radius_km'] ?? null,
            'include_cross_border' => (bool) (
                $input['include_cross_border'] ?? false
            ),
            'required_keywords' => $requiredKeywords,
            'excluded_keywords' => $excludedKeywords,
            'minimum_profit_minor' => $input['minimum_profit_minor'] ?? null,
            'profit_currency_code' => isset($input['profit_currency_code'])
                ? mb_strtoupper((string) $input['profit_currency_code'])
                : null,
            'minimum_margin_basis_points' => $input[
                'minimum_margin_basis_points'
            ] ?? null,
            'minimum_deal_score_basis_points' => $input[
                'minimum_deal_score_basis_points'
            ] ?? null,
            'maximum_risk_score' => $input['maximum_risk_score'] ?? null,
            'notification_channels' => $channels,
        ];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizedList(
        array $values,
        bool $uppercase = false,
    ): array {
        $normalized = [];

        foreach ($values as $value) {
            $item = trim((string) $value);

            if ($item === '') {
                continue;
            }

            $item = $uppercase
                ? mb_strtoupper($item)
                : mb_strtolower($item);
            $normalized[$item] = true;
        }

        $items = array_keys($normalized);
        sort($items, SORT_STRING);

        return $items;
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }
}
