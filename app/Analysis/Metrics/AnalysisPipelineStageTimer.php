<?php

namespace App\Analysis\Metrics;

use App\Enums\Analyses\AnalysisPipelineStage;
use Closure;
use Throwable;

final class AnalysisPipelineStageTimer
{
    private readonly int $startedAtNanoseconds;

    /** @var array<string, int> */
    private array $durations = [];

    private ?AnalysisPipelineStage $failedStage = null;

    public function __construct()
    {
        $this->startedAtNanoseconds = hrtime(true);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function measure(
        AnalysisPipelineStage $stage,
        Closure $operation,
    ): mixed {
        $startedAt = hrtime(true);

        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->failedStage ??= $stage;

            throw $exception;
        } finally {
            $elapsed = $this->microsecondsSince($startedAt);
            $this->durations[$stage->value] = (
                $this->durations[$stage->value] ?? 0
            ) + $elapsed;
        }
    }

    public function failedStage(): ?AnalysisPipelineStage
    {
        return $this->failedStage;
    }

    /** @return array<string, int|null> */
    public function durationColumns(): array
    {
        $columns = [];

        foreach (AnalysisPipelineStage::cases() as $stage) {
            $columns[$stage->durationColumn()] = $this->durations[$stage->value]
                ?? null;
        }

        return $columns;
    }

    public function totalMicroseconds(): int
    {
        return $this->microsecondsSince($this->startedAtNanoseconds);
    }

    private function microsecondsSince(int $startedAtNanoseconds): int
    {
        return max(1, intdiv(hrtime(true) - $startedAtNanoseconds, 1000));
    }
}
