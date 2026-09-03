<?php

namespace App\Analysis\Governance;

use App\Analysis\Contracts\ConfiguredListingAiAnalyzer;
use App\Analysis\Data\AiAnalysisData;
use App\Analysis\Data\AnalysisInputData;
use App\Enums\Analyses\AnalysisProviderBudgetScope;
use App\Enums\Analyses\AnalysisProviderCircuitState;
use App\Enums\Analyses\AnalysisProviderUsageStatus;
use App\Exceptions\AnalysisProviderException;
use App\Models\AiAnalysis;
use App\Models\Analysis;
use App\Models\AnalysisProviderBudgetPeriod;
use App\Models\AnalysisProviderCircuit;
use App\Models\AnalysisProviderUsage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class AnalysisProviderGovernor
{
    public function __construct(
        private readonly AnalysisProviderGovernanceConfiguration $configuration,
    ) {}

    public function reserve(
        Analysis $analysis,
        AiAnalysis $aiAnalysis,
        ConfiguredListingAiAnalyzer $provider,
        AnalysisInputData $input,
    ): AnalysisProviderUsage {
        if (! $this->configuration->isValid()) {
            throw new AnalysisProviderException(
                'analysis_provider_governance_not_configured',
            );
        }
        $this->reconcileStaleReservations($analysis->getKey(), $aiAnalysis->getKey());

        $userId = $analysis->requested_by_user_id;

        if (! is_int($userId) && ! ctype_digit((string) $userId)) {
            throw new AnalysisProviderException('analysis_provider_actor_unavailable');
        }

        $maximumCost = $provider->maximumCostMinor($input);

        if ($maximumCost < 0) {
            throw new AnalysisProviderException(
                'analysis_provider_cost_reservation_invalid',
            );
        }

        // Free-tier calls still reserve one cent so every request crosses the same atomic gates.
        $reservation = max(1, $maximumCost);
        $budgets = $this->configuration->budgets();

        if ($reservation < 1 || $reservation > $budgets['task']) {
            throw new AnalysisProviderException('analysis_provider_task_budget_exceeded');
        }

        $periodStart = CarbonImmutable::now('UTC')->startOfMonth();
        $scopes = $this->scopes(
            $analysis->organization_id,
            (string) $userId,
            $budgets,
        );

        return DB::transaction(function () use (
            $analysis,
            $aiAnalysis,
            $periodStart,
            $provider,
            $reservation,
            $scopes,
            $userId,
        ): AnalysisProviderUsage {
            $circuit = $this->lockedCircuit(
                $provider->provider(),
                $provider->model(),
            );
            $this->assertCircuitAllowsRequest($circuit, $aiAnalysis->getKey());
            $periods = $this->lockedBudgetPeriods(
                $periodStart,
                $scopes,
                create: true,
            );

            foreach ($periods as $period) {
                $scope = $scopes[$period->scope_type->value] ?? null;

                if ($scope === null) {
                    throw new LogicException('The analysis provider budget scope is inconsistent.');
                }

                $current = $period->reserved_cost_minor + $period->consumed_cost_minor;

                if ($current + $reservation > $scope['limit']) {
                    throw new AnalysisProviderException($scope['failure_code']);
                }
            }

            foreach ($periods as $period) {
                $period->reserved_cost_minor += $reservation;
                $period->save();
            }

            return AnalysisProviderUsage::query()->create([
                'organization_id' => $analysis->organization_id,
                'user_id' => (int) $userId,
                'analysis_id' => $analysis->getKey(),
                'ai_analysis_id' => $aiAnalysis->getKey(),
                'provider' => $provider->provider(),
                'model' => $provider->model(),
                'period_start' => $periodStart->toDateString(),
                'status' => AnalysisProviderUsageStatus::Reserved,
                'reserved_cost_minor' => $reservation,
                'actual_cost_minor' => null,
                'cost_currency' => 'USD',
                'failure_code' => null,
                'started_at' => now(),
                'completed_at' => null,
            ]);
        }, attempts: 3);
    }

    public function complete(AnalysisProviderUsage $usage, AiAnalysisData $result): void
    {
        if ($result->estimatedCostCurrency !== 'USD') {
            $this->fail(
                $usage,
                new AnalysisProviderException('analysis_provider_cost_currency_invalid'),
            );

            throw new AnalysisProviderException('analysis_provider_cost_currency_invalid');
        }

        $withinReservation = DB::transaction(function () use ($usage, $result): bool {
            $snapshot = AnalysisProviderUsage::query()->findOrFail($usage->getKey());
            $circuit = $this->lockedCircuit($snapshot->provider, $snapshot->model);
            $periods = $this->lockedUsageBudgetPeriods($snapshot);
            $lockedUsage = AnalysisProviderUsage::query()
                ->lockForUpdate()
                ->findOrFail($usage->getKey());

            if ($lockedUsage->status !== AnalysisProviderUsageStatus::Reserved) {
                return $lockedUsage->status === AnalysisProviderUsageStatus::Completed;
            }

            $this->settleBudgetPeriods(
                $periods,
                $lockedUsage->reserved_cost_minor,
                $result->estimatedCostMinor,
            );
            $withinReservation = $result->estimatedCostMinor
                <= $lockedUsage->reserved_cost_minor;

            $lockedUsage->update([
                'status' => $withinReservation
                    ? AnalysisProviderUsageStatus::Completed
                    : AnalysisProviderUsageStatus::Uncertain,
                'actual_cost_minor' => $result->estimatedCostMinor,
                'failure_code' => $withinReservation
                    ? null
                    : 'analysis_provider_cost_exceeded_reservation',
                'completed_at' => now(),
            ]);

            if ($withinReservation) {
                $this->recordCircuitSuccess($circuit, $lockedUsage->ai_analysis_id);
            } else {
                $this->recordCircuitFailure(
                    $circuit,
                    'analysis_provider_cost_exceeded_reservation',
                );
            }

            return $withinReservation;
        }, attempts: 3);

        if (! $withinReservation) {
            throw new AnalysisProviderException(
                'analysis_provider_cost_exceeded_reservation',
            );
        }
    }

    public function fail(AnalysisProviderUsage $usage, Throwable $exception): void
    {
        DB::transaction(function () use ($usage, $exception): void {
            $snapshot = AnalysisProviderUsage::query()->find($usage->getKey());

            if ($snapshot === null) {
                return;
            }

            $circuit = $this->lockedCircuit($snapshot->provider, $snapshot->model);
            $periods = $this->lockedUsageBudgetPeriods($snapshot);
            $lockedUsage = AnalysisProviderUsage::query()
                ->lockForUpdate()
                ->findOrFail($usage->getKey());

            if ($lockedUsage->status !== AnalysisProviderUsageStatus::Reserved) {
                return;
            }

            $failureCode = $exception instanceof AnalysisProviderException
                ? $exception->reasonCode
                : class_basename($exception);

            $this->settleBudgetPeriods(
                $periods,
                $lockedUsage->reserved_cost_minor,
                $lockedUsage->reserved_cost_minor,
            );
            $lockedUsage->update([
                'status' => AnalysisProviderUsageStatus::Uncertain,
                'actual_cost_minor' => $lockedUsage->reserved_cost_minor,
                'failure_code' => $failureCode,
                'completed_at' => now(),
            ]);
            $this->recordCircuitFailure($circuit, $failureCode);
        }, attempts: 3);
    }

    private function reconcileStaleReservations(string $analysisId, string $currentAiAnalysisId): void
    {
        $stale = AnalysisProviderUsage::query()
            ->where('analysis_id', $analysisId)
            ->where('status', AnalysisProviderUsageStatus::Reserved)
            ->where('ai_analysis_id', '!=', $currentAiAnalysisId)
            ->whereHas('aiAnalysis', fn (Builder $query) => $query->where('status', 'failed'))
            ->get();

        foreach ($stale as $usage) {
            $this->fail(
                $usage,
                new AnalysisProviderException('analysis_provider_processing_lease_expired'),
            );
        }
    }

    /**
     * @param  array{task: int, global: int, organization: int, user: int}  $budgets
     * @return array<string, array{scope_id: string, limit: int, failure_code: string}>
     */
    private function scopes(string $organizationId, string $userId, array $budgets): array
    {
        return [
            AnalysisProviderBudgetScope::Global->value => [
                'scope_id' => 'global',
                'limit' => $budgets['global'],
                'failure_code' => 'analysis_provider_global_budget_exhausted',
            ],
            AnalysisProviderBudgetScope::Organization->value => [
                'scope_id' => $organizationId,
                'limit' => $budgets['organization'],
                'failure_code' => 'analysis_provider_organization_budget_exhausted',
            ],
            AnalysisProviderBudgetScope::User->value => [
                'scope_id' => $userId,
                'limit' => $budgets['user'],
                'failure_code' => 'analysis_provider_user_budget_exhausted',
            ],
        ];
    }

    private function lockedCircuit(string $provider, string $model): AnalysisProviderCircuit
    {
        AnalysisProviderCircuit::query()->upsert([[
            'id' => (string) Str::ulid(),
            'provider' => $provider,
            'model' => $model,
            'state' => AnalysisProviderCircuitState::Closed->value,
            'consecutive_failures' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['provider', 'model'], ['updated_at']);

        return AnalysisProviderCircuit::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertCircuitAllowsRequest(
        AnalysisProviderCircuit $circuit,
        string $aiAnalysisId,
    ): void {
        $now = now();

        if (
            $circuit->state === AnalysisProviderCircuitState::Open
            && $circuit->retry_at?->isFuture()
        ) {
            throw new AnalysisProviderException('analysis_provider_circuit_open');
        }

        if ($circuit->state === AnalysisProviderCircuitState::HalfOpen) {
            throw new AnalysisProviderException('analysis_provider_circuit_open');
        }

        if ($circuit->state === AnalysisProviderCircuitState::Open) {
            $circuit->update([
                'state' => AnalysisProviderCircuitState::HalfOpen,
                'probe_ai_analysis_id' => $aiAnalysisId,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  array<string, array{scope_id: string, limit: int, failure_code: string}>  $scopes
     * @return list<AnalysisProviderBudgetPeriod>
     */
    private function lockedBudgetPeriods(
        CarbonImmutable $periodStart,
        array $scopes,
        bool $create,
    ): array {
        if ($create) {
            $timestamp = now();
            $rows = [];

            foreach ($scopes as $scopeType => $scope) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'period_start' => $periodStart->toDateString(),
                    'scope_type' => $scopeType,
                    'scope_id' => $scope['scope_id'],
                    'reserved_cost_minor' => 0,
                    'consumed_cost_minor' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            AnalysisProviderBudgetPeriod::query()->upsert(
                $rows,
                ['period_start', 'scope_type', 'scope_id'],
                ['updated_at'],
            );
        }

        $periods = AnalysisProviderBudgetPeriod::query()
            ->whereDate('period_start', $periodStart->toDateString())
            ->where(function (Builder $query) use ($scopes): void {
                foreach ($scopes as $scopeType => $scope) {
                    $query->orWhere(function (Builder $query) use ($scope, $scopeType): void {
                        $query->where('scope_type', $scopeType)
                            ->where('scope_id', $scope['scope_id']);
                    });
                }
            })
            ->orderBy('scope_type')
            ->lockForUpdate()
            ->get()
            ->all();

        if (count($periods) !== count($scopes)) {
            throw new LogicException('The analysis provider budget periods are incomplete.');
        }

        return $periods;
    }

    /** @return list<AnalysisProviderBudgetPeriod> */
    private function lockedUsageBudgetPeriods(AnalysisProviderUsage $usage): array
    {
        $budgets = $this->configuration->budgets();
        $scopes = $this->scopes(
            $usage->organization_id,
            (string) $usage->user_id,
            $budgets,
        );

        return $this->lockedBudgetPeriods(
            CarbonImmutable::parse($usage->period_start, 'UTC'),
            $scopes,
            create: false,
        );
    }

    /** @param list<AnalysisProviderBudgetPeriod> $periods */
    private function settleBudgetPeriods(
        array $periods,
        int $reservation,
        int $actualCost,
    ): void {
        foreach ($periods as $period) {
            if ($period->reserved_cost_minor < $reservation) {
                throw new LogicException('The analysis provider budget reservation is inconsistent.');
            }

            $period->reserved_cost_minor -= $reservation;
            $period->consumed_cost_minor += $actualCost;
            $period->save();
        }
    }

    private function recordCircuitSuccess(
        AnalysisProviderCircuit $circuit,
        string $aiAnalysisId,
    ): void {
        if (
            $circuit->state === AnalysisProviderCircuitState::Open
            || (
                $circuit->state === AnalysisProviderCircuitState::HalfOpen
                && $circuit->probe_ai_analysis_id !== $aiAnalysisId
            )
        ) {
            $circuit->update(['last_success_at' => now()]);

            return;
        }

        $circuit->update([
            'state' => AnalysisProviderCircuitState::Closed,
            'consecutive_failures' => 0,
            'opened_at' => null,
            'retry_at' => null,
            'probe_ai_analysis_id' => null,
            'last_failure_code' => null,
            'last_success_at' => now(),
        ]);
    }

    private function recordCircuitFailure(
        AnalysisProviderCircuit $circuit,
        string $failureCode,
    ): void {
        $configuration = $this->configuration->circuit();
        $failures = $circuit->consecutive_failures + 1;
        $open = $circuit->state !== AnalysisProviderCircuitState::Closed
            || $failures >= $configuration['failure_threshold'];

        $circuit->update([
            'state' => $open
                ? AnalysisProviderCircuitState::Open
                : AnalysisProviderCircuitState::Closed,
            'consecutive_failures' => $failures,
            'opened_at' => $open ? now() : null,
            'retry_at' => $open
                ? now()->addSeconds($configuration['cooldown_seconds'])
                : null,
            'probe_ai_analysis_id' => null,
            'last_failure_code' => $failureCode,
            'last_failure_at' => now(),
        ]);
    }
}
