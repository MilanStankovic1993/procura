<?php

namespace App\Analysis\Monitoring;

use App\Analysis\Governance\AnalysisProviderGovernanceConfiguration;
use App\Analysis\Monitoring\Data\AnalysisProviderMonitoringReport;
use App\Enums\Analyses\AnalysisProviderBudgetScope;
use App\Enums\Analyses\AnalysisProviderCircuitState;
use App\Enums\Analyses\AnalysisProviderUsageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AnalysisProviderMonitor
{
    private const SIGNAL_KEYS = [
        'open_circuits',
        'half_open_circuits',
        'stale_reservations',
        'uncertain_outcomes_recent',
        'rate_limits_recent',
        'server_errors_recent',
        'cost_overruns_recent',
        'budget_scopes_near_limit',
    ];

    public function __construct(
        private readonly AnalysisProviderMonitoringConfiguration $configuration,
        private readonly AnalysisProviderGovernanceConfiguration $governance,
    ) {}

    public function inspect(): AnalysisProviderMonitoringReport
    {
        $checkedAt = CarbonImmutable::now('UTC');
        $thresholds = $this->configuration->thresholds();
        $budgets = $this->governance->budgets();
        $recentSince = $checkedAt->subMinutes(
            $thresholds['recent_window_minutes'],
        );
        $staleBefore = $checkedAt->subMinutes(
            $thresholds['stale_reservation_minutes'],
        );
        $uncertain = AnalysisProviderUsageStatus::Uncertain->value;

        $queries = [
            $this->signalQuery('analysis_provider_circuits', 'open_circuits')
                ->where('state', AnalysisProviderCircuitState::Open->value),
            $this->signalQuery('analysis_provider_circuits', 'half_open_circuits')
                ->where('state', AnalysisProviderCircuitState::HalfOpen->value),
            $this->signalQuery('analysis_provider_usages', 'stale_reservations')
                ->where('status', AnalysisProviderUsageStatus::Reserved->value)
                ->where('started_at', '<=', $staleBefore),
            $this->signalQuery('analysis_provider_usages', 'uncertain_outcomes_recent')
                ->where('status', $uncertain)
                ->where('completed_at', '>=', $recentSince),
            $this->signalQuery('analysis_provider_usages', 'rate_limits_recent')
                ->where('status', $uncertain)
                ->where('failure_code', 'analysis_provider_rate_limited')
                ->where('completed_at', '>=', $recentSince),
            $this->signalQuery('analysis_provider_usages', 'server_errors_recent')
                ->where('status', $uncertain)
                ->where('failure_code', 'analysis_provider_server_error')
                ->where('completed_at', '>=', $recentSince),
            $this->signalQuery('analysis_provider_usages', 'cost_overruns_recent')
                ->where('status', $uncertain)
                ->where(
                    'failure_code',
                    'analysis_provider_cost_exceeded_reservation',
                )
                ->where('completed_at', '>=', $recentSince),
            $this->budgetSignalQuery(
                $checkedAt,
                $thresholds['budget_utilization_basis_points'],
                $budgets,
            ),
        ];
        $union = array_shift($queries);

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        $counts = array_fill_keys(self::SIGNAL_KEYS, 0);
        $rows = DB::query()
            ->fromSub($union, 'analysis_provider_monitoring_signals')
            ->get();

        foreach ($rows as $row) {
            $signal = (string) $row->monitoring_signal_key;

            if (array_key_exists($signal, $counts)) {
                $counts[$signal] = (int) $row->monitoring_signal_count;
            }
        }

        return new AnalysisProviderMonitoringReport(
            checkedAt: $checkedAt,
            counts: $counts,
            thresholds: $thresholds,
        );
    }

    private function signalQuery(string $table, string $signal): Builder
    {
        return DB::table($table)->selectRaw(
            '? AS monitoring_signal_key, COUNT(*) AS monitoring_signal_count',
            [$signal],
        );
    }

    /**
     * @param  array{task: int, global: int, organization: int, user: int}  $budgets
     */
    private function budgetSignalQuery(
        CarbonImmutable $checkedAt,
        int $utilizationBasisPoints,
        array $budgets,
    ): Builder {
        return $this->signalQuery(
            'analysis_provider_budget_periods',
            'budget_scopes_near_limit',
        )
            ->where(
                'period_start',
                '>=',
                $checkedAt->startOfMonth(),
            )
            ->where(
                'period_start',
                '<',
                $checkedAt->addMonth()->startOfMonth(),
            )
            ->whereRaw(
                '((reserved_cost_minor + consumed_cost_minor) * 10000) >= '
                .'((CASE scope_type WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? ELSE 0 END) * ?)',
                [
                    AnalysisProviderBudgetScope::Global->value,
                    $budgets['global'],
                    AnalysisProviderBudgetScope::Organization->value,
                    $budgets['organization'],
                    AnalysisProviderBudgetScope::User->value,
                    $budgets['user'],
                    $utilizationBasisPoints,
                ],
            );
    }
}
