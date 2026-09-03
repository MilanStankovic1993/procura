<?php

namespace App\Operations\Capacity\Data;

use Carbon\CarbonImmutable;

final readonly class CapacityBaselineReport
{
    /**
     * @param  array<string, CapacityProbeResult>  $probes
     */
    public function __construct(
        public string $environment,
        public CarbonImmutable $measuredAt,
        public array $probes,
    ) {}

    public function passed(): bool
    {
        foreach ($this->probes as $probe) {
            if (! in_array($probe->status, ['passed', 'skipped'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *     status: string,
     *     environment: string,
     *     measured_at: string,
     *     probes: array<string, array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->passed() ? 'passed' : 'failed',
            'environment' => $this->environment,
            'measured_at' => $this->measuredAt->toIso8601String(),
            'probes' => collect($this->probes)
                ->map(
                    fn (CapacityProbeResult $probe): array => (
                        $probe->toArray()
                    ),
                )
                ->all(),
        ];
    }
}
