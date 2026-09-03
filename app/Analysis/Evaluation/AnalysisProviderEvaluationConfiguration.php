<?php

namespace App\Analysis\Evaluation;

use RuntimeException;

final class AnalysisProviderEvaluationConfiguration
{
    public function externalCallsEnabled(): bool
    {
        return config(
            'analyses.provider_evaluation.external_calls_enabled',
        ) === true;
    }

    public function datasetContractVersion(): string
    {
        return $this->version('dataset_contract_version');
    }

    public function reportContractVersion(): string
    {
        return $this->version('report_contract_version');
    }

    public function budgetVersion(): string
    {
        return $this->version('budget_version');
    }

    public function datasetPath(): string
    {
        $relative = config('analyses.provider_evaluation.dataset_path');

        if (
            ! is_string($relative)
            || preg_match(
                '/\Aresources\/analysis\/[a-z0-9][a-z0-9._-]{0,127}\.json\z/',
                $relative,
            ) !== 1
            || str_contains($relative, '..')
        ) {
            throw new RuntimeException(
                'The analysis provider evaluation dataset path is invalid.',
            );
        }

        $path = realpath(base_path($relative));
        $root = realpath(base_path('resources/analysis'));

        if (
            $path === false
            || $root === false
            || ! is_file($path)
            || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException(
                'The analysis provider evaluation dataset is unavailable.',
            );
        }

        $size = filesize($path);

        if (
            $size === false
            || $size < 2
            || $size > $this->maximumDatasetBytes()
        ) {
            throw new RuntimeException(
                'The analysis provider evaluation dataset size is invalid.',
            );
        }

        return $path;
    }

    public function minimumCases(): int
    {
        return $this->integer('minimum_cases', 1, 1000);
    }

    public function maximumCases(): int
    {
        $maximum = $this->integer('maximum_cases', 1, 1000);

        if ($maximum < $this->minimumCases()) {
            throw new RuntimeException(
                'The analysis provider evaluation case bounds are invalid.',
            );
        }

        return $maximum;
    }

    public function maximumDatasetBytes(): int
    {
        return $this->integer('maximum_dataset_bytes', 1024, 10_485_760);
    }

    public function maximumTotalCostMinor(): int
    {
        return $this->integer('maximum_total_cost_minor', 0, 100_000);
    }

    /** @return array<string, int|string> */
    public function budget(): array
    {
        return [
            'version' => $this->budgetVersion(),
            'maximum_total_cost_minor' => $this->maximumTotalCostMinor(),
            'minimum_case_pass_rate_basis_points' => $this->rate(
                'minimum_case_pass_rate_basis_points',
            ),
            'minimum_title_exact_rate_basis_points' => $this->rate(
                'minimum_title_exact_rate_basis_points',
            ),
            'minimum_description_exact_rate_basis_points' => $this->rate(
                'minimum_description_exact_rate_basis_points',
            ),
            'minimum_needs_input_exact_rate_basis_points' => $this->rate(
                'minimum_needs_input_exact_rate_basis_points',
            ),
            'minimum_immutable_projection_rate_basis_points' => $this->rate(
                'minimum_immutable_projection_rate_basis_points',
            ),
            'minimum_confidence_range_rate_basis_points' => $this->rate(
                'minimum_confidence_range_rate_basis_points',
            ),
        ];
    }

    public function releaseSha(mixed $value): string
    {
        if (
            ! is_string($value)
            || preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $value)
                !== 1
        ) {
            throw new RuntimeException(
                'The analysis provider evaluation release commit is invalid.',
            );
        }

        return $value;
    }

    public function providerOrModel(mixed $value, string $label): string
    {
        if (
            ! is_string($value)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/', $value)
                !== 1
        ) {
            throw new RuntimeException(
                "The expected analysis {$label} is invalid.",
            );
        }

        return $value;
    }

    public function assertValid(): void
    {
        $this->datasetContractVersion();
        $this->reportContractVersion();
        $this->budget();
        $this->maximumCases();
        $this->maximumDatasetBytes();
        $this->datasetPath();
    }

    private function version(string $key): string
    {
        $value = config("analyses.provider_evaluation.{$key}");

        if (
            ! is_string($value)
            || preg_match('/\A[a-z0-9][a-z0-9:_-]{1,127}\z/', $value) !== 1
        ) {
            throw new RuntimeException(
                "The analysis provider evaluation {$key} is invalid.",
            );
        }

        return $value;
    }

    private function rate(string $key): int
    {
        return $this->integer($key, 0, 10_000);
    }

    private function integer(string $key, int $minimum, int $maximum): int
    {
        $value = config("analyses.provider_evaluation.{$key}");

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                "The analysis provider evaluation {$key} is invalid.",
            );
        }

        return $value;
    }
}
