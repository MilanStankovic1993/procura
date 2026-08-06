<?php

namespace App\SellPriceIntelligence\Metrics;

use App\Enums\Sell\SellPriceIntelligenceMetricOperation;
use App\Enums\Sell\SellPriceIntelligenceMetricStage;
use Closure;
use LogicException;

final class SellPriceIntelligenceMetricTimer
{
    /** @var array<string, int> */
    private array $durations = [];

    private int $scopeCount = 0;

    private int $candidateCount = 0;

    private int $includedCount = 0;

    private int $excludedCount = 0;

    private int $bandInputCount = 0;

    private int $outlierCount = 0;

    private int $selectionReplayCount = 0;

    private int $priceBandReplayCount = 0;

    private ?int $finishedAtNanoseconds = null;

    private function __construct(
        public readonly SellPriceIntelligenceMetricOperation $operation,
        private readonly int $startedAtNanoseconds,
    ) {
        foreach (SellPriceIntelligenceMetricStage::cases() as $stage) {
            $this->durations[$stage->value] = 0;
        }
    }

    public static function start(
        SellPriceIntelligenceMetricOperation $operation,
    ): self {
        return new self($operation, hrtime(true));
    }

    /** @template TValue @param Closure(): TValue $callback @return TValue */
    public function measure(
        SellPriceIntelligenceMetricStage $stage,
        Closure $callback,
    ): mixed {
        $this->assertRunning();
        $startedAt = hrtime(true);

        try {
            return $callback();
        } finally {
            $this->durations[$stage->value] += $this->microsecondsSince(
                $startedAt,
            );
        }
    }

    public function observeScope(
        int $candidateCount,
        int $includedCount,
        int $excludedCount,
        int $bandInputCount,
        int $outlierCount,
        bool $selectionReplayed,
        bool $priceBandReplayed,
    ): void {
        $this->assertRunning();
        $this->scopeCount++;
        $this->candidateCount += $candidateCount;
        $this->includedCount += $includedCount;
        $this->excludedCount += $excludedCount;
        $this->bandInputCount += $bandInputCount;
        $this->outlierCount += $outlierCount;
        $this->selectionReplayCount += $selectionReplayed ? 1 : 0;
        $this->priceBandReplayCount += $priceBandReplayed ? 1 : 0;
    }

    public function finish(): void
    {
        $this->assertRunning();

        if ($this->scopeCount < 1) {
            throw new LogicException(
                'A Sell price-intelligence metric requires at least one scope.',
            );
        }

        $this->finishedAtNanoseconds = hrtime(true);
    }

    /** @return array<string, int> */
    public function durationColumns(): array
    {
        $this->assertFinished();
        $columns = [];

        foreach (SellPriceIntelligenceMetricStage::cases() as $stage) {
            $columns[$stage->durationColumn()] = $this->durations[$stage->value];
        }

        return $columns;
    }

    /** @return array<string, int> */
    public function countColumns(): array
    {
        $this->assertFinished();

        return [
            'scope_count' => $this->scopeCount,
            'candidate_count' => $this->candidateCount,
            'included_count' => $this->includedCount,
            'excluded_count' => $this->excludedCount,
            'band_input_count' => $this->bandInputCount,
            'outlier_count' => $this->outlierCount,
            'selection_replay_count' => $this->selectionReplayCount,
            'price_band_replay_count' => $this->priceBandReplayCount,
        ];
    }

    public function totalMicroseconds(): int
    {
        $this->assertFinished();

        return max(1, intdiv(
            $this->finishedAtNanoseconds - $this->startedAtNanoseconds,
            1000,
        ));
    }

    private function microsecondsSince(int $startedAtNanoseconds): int
    {
        return max(1, intdiv(hrtime(true) - $startedAtNanoseconds, 1000));
    }

    private function assertRunning(): void
    {
        if ($this->finishedAtNanoseconds !== null) {
            throw new LogicException(
                'A completed Sell price-intelligence metric is immutable.',
            );
        }
    }

    private function assertFinished(): void
    {
        if ($this->finishedAtNanoseconds === null) {
            throw new LogicException(
                'The Sell price-intelligence metric has not completed.',
            );
        }
    }
}
