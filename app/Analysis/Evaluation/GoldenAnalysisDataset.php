<?php

namespace App\Analysis\Evaluation;

use JsonException;
use RuntimeException;

final readonly class GoldenAnalysisDataset
{
    /** @param list<array<string, mixed>> $cases */
    public function __construct(
        public string $version,
        public string $sha256,
        public array $cases,
    ) {}

    /** @throws JsonException */
    public static function load(
        AnalysisProviderEvaluationConfiguration $configuration,
    ): self {
        $contents = file_get_contents($configuration->datasetPath());

        if ($contents === false) {
            throw new RuntimeException(
                'The analysis provider evaluation dataset could not be read.',
            );
        }

        $payload = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);

        return self::fromPayload(
            $payload,
            $configuration,
            hash('sha256', $contents),
        );
    }

    public static function fromPayload(
        mixed $payload,
        AnalysisProviderEvaluationConfiguration $configuration,
        string $sha256,
    ): self {
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new RuntimeException('The golden dataset digest is invalid.');
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('The golden dataset root is invalid.');
        }

        self::exactKeys($payload, [
            'contract_version',
            'dataset_version',
            'classification',
            'cases',
        ]);

        if (
            $payload['contract_version']
                !== $configuration->datasetContractVersion()
            || $payload['classification'] !== 'synthetic_non_confidential'
            || ! is_string($payload['dataset_version'])
            || preg_match(
                '/\A[a-z0-9][a-z0-9:_-]{1,127}\z/',
                $payload['dataset_version'],
            ) !== 1
        ) {
            throw new RuntimeException('The golden dataset contract is invalid.');
        }

        $cases = $payload['cases'];

        if (
            ! is_array($cases)
            || ! array_is_list($cases)
            || count($cases) < $configuration->minimumCases()
            || count($cases) > $configuration->maximumCases()
        ) {
            throw new RuntimeException('The golden dataset case count is invalid.');
        }

        $validated = [];
        $keys = [];

        foreach ($cases as $case) {
            $validatedCase = self::case($case);

            if (isset($keys[$validatedCase['key']])) {
                throw new RuntimeException(
                    'The golden dataset case keys must be unique.',
                );
            }

            $keys[$validatedCase['key']] = true;
            $validated[] = $validatedCase;
        }

        return new self(
            version: $payload['dataset_version'],
            sha256: $sha256,
            cases: $validated,
        );
    }

    /** @return array<string, mixed> */
    private static function case(mixed $case): array
    {
        if (! is_array($case) || array_is_list($case)) {
            throw new RuntimeException('A golden dataset case is invalid.');
        }

        self::exactKeys($case, ['key', 'input', 'expected']);

        if (
            ! is_string($case['key'])
            || preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $case['key']) !== 1
        ) {
            throw new RuntimeException('A golden dataset case key is invalid.');
        }

        return [
            'key' => $case['key'],
            'input' => self::input($case['input']),
            'expected' => self::expected($case['expected']),
        ];
    }

    /** @return array<string, mixed> */
    private static function input(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new RuntimeException('A golden dataset input is invalid.');
        }

        self::exactKeys($input, ['listing', 'market_scope', 'evidence']);
        $listing = $input['listing'];
        $market = $input['market_scope'];
        $evidence = $input['evidence'];

        if (
            ! is_array($listing)
            || array_is_list($listing)
            || ! is_array($market)
            || array_is_list($market)
            || ! is_array($evidence)
            || ! array_is_list($evidence)
            || count($evidence) > 12
        ) {
            throw new RuntimeException('A golden dataset input shape is invalid.');
        }

        self::exactKeys($listing, [
            'marketplace_name',
            'title',
            'description',
            'asking_price_minor',
            'currency_code',
        ]);
        self::exactKeys($market, [
            'source_country_code',
            'target_country_code',
        ]);
        self::boundedString($listing['marketplace_name'], 1, 120);
        self::boundedString($listing['title'], 1, 500);
        self::nullableString($listing['description'], 10_000);

        if (
            $listing['asking_price_minor'] !== null
            && (! is_int($listing['asking_price_minor'])
                || $listing['asking_price_minor'] < 0)
        ) {
            throw new RuntimeException('A golden dataset price is invalid.');
        }

        self::nullableCode($listing['currency_code'], 3);
        self::nullableCode($market['source_country_code'], 2);
        self::nullableCode($market['target_country_code'], 2);

        foreach ($evidence as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new RuntimeException(
                    'A golden dataset evidence item is invalid.',
                );
            }

            self::exactKeys($item, ['kind', 'mime_type', 'width', 'height']);
            self::boundedString($item['kind'], 1, 64);
            self::boundedString($item['mime_type'], 1, 128);

            foreach (['width', 'height'] as $dimension) {
                if (
                    $item[$dimension] !== null
                    && (! is_int($item[$dimension])
                        || $item[$dimension] < 1
                        || $item[$dimension] > 100_000)
                ) {
                    throw new RuntimeException(
                        'A golden dataset evidence dimension is invalid.',
                    );
                }
            }
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private static function expected(mixed $expected): array
    {
        if (! is_array($expected) || array_is_list($expected)) {
            throw new RuntimeException('A golden dataset expectation is invalid.');
        }

        self::exactKeys($expected, [
            'title',
            'description',
            'needs_input',
            'minimum_confidence_basis_points',
            'maximum_confidence_basis_points',
        ]);
        self::boundedString($expected['title'], 1, 500);
        self::nullableString($expected['description'], 10_000);

        if (
            ! is_array($expected['needs_input'])
            || ! array_is_list($expected['needs_input'])
            || count($expected['needs_input']) > 20
        ) {
            throw new RuntimeException(
                'A golden dataset needs-input expectation is invalid.',
            );
        }

        foreach ($expected['needs_input'] as $code) {
            if (
                ! is_string($code)
                || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $code) !== 1
            ) {
                throw new RuntimeException(
                    'A golden dataset needs-input code is invalid.',
                );
            }
        }

        $minimum = $expected['minimum_confidence_basis_points'];
        $maximum = $expected['maximum_confidence_basis_points'];

        if (
            ! is_int($minimum)
            || ! is_int($maximum)
            || $minimum < 0
            || $maximum > 10_000
            || $minimum > $maximum
        ) {
            throw new RuntimeException(
                'A golden dataset confidence range is invalid.',
            );
        }

        return $expected;
    }

    /** @param array<mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            throw new RuntimeException(
                'The golden dataset contains missing or unknown fields.',
            );
        }
    }

    private static function boundedString(
        mixed $value,
        int $minimum,
        int $maximum,
    ): void {
        if (
            ! is_string($value)
            || mb_strlen($value) < $minimum
            || mb_strlen($value) > $maximum
        ) {
            throw new RuntimeException('A golden dataset string is invalid.');
        }
    }

    private static function nullableString(mixed $value, int $maximum): void
    {
        if ($value !== null) {
            self::boundedString($value, 1, $maximum);
        }
    }

    private static function nullableCode(mixed $value, int $length): void
    {
        if (
            $value !== null
            && (! is_string($value)
                || preg_match("/\A[A-Z]{{$length}}\z/", $value) !== 1)
        ) {
            throw new RuntimeException('A golden dataset code is invalid.');
        }
    }
}
