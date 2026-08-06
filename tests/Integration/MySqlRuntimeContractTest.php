<?php

use App\BrokerRequests\Operations\BrokerOperationsMonitor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

test('the production database family satisfies the schema and session contract', function () {
    expect(DB::getDriverName())
        ->toBe('mysql');

    $database = DB::getDatabaseName();
    $session = DB::selectOne(<<<'SQL'
        SELECT
            @@SESSION.sql_mode AS sql_mode,
            @@SESSION.time_zone AS time_zone,
            @@character_set_connection AS character_set_connection,
            @@collation_connection AS collation_connection
        SQL);
    $tableEngines = DB::table('information_schema.tables')
        ->selectRaw('ENGINE AS table_engine')
        ->where('table_schema', $database)
        ->where('table_type', 'BASE TABLE')
        ->pluck('table_engine');
    $oversizedIndexes = DB::table('information_schema.statistics')
        ->where('table_schema', $database)
        ->whereRaw('CHAR_LENGTH(index_name) > 64')
        ->count();
    $foreignKeys = DB::table('information_schema.referential_constraints')
        ->where('constraint_schema', $database)
        ->count();
    $sellMetricColumns = DB::table('information_schema.columns')
        ->where('table_schema', $database)
        ->where('table_name', 'sell_price_intelligence_metrics')
        ->orderBy('ordinal_position')
        ->pluck('column_name')
        ->all();
    $sellMetricIndexes = DB::table('information_schema.statistics')
        ->where('table_schema', $database)
        ->where('table_name', 'sell_price_intelligence_metrics')
        ->pluck('index_name')
        ->unique()
        ->values()
        ->all();
    $sellMetricForeignKeys = DB::table(
        'information_schema.referential_constraints',
    )
        ->where('constraint_schema', $database)
        ->where('table_name', 'sell_price_intelligence_metrics')
        ->count();
    $brokerOperations = app(BrokerOperationsMonitor::class)->inspect();
    $migrationFiles = count(glob(database_path('migrations/*.php')) ?: []);

    expect(config('testing.mysql_enabled'))
        ->toBeTrue()
        ->and($database)
        ->toMatch('/\Aprocura_ci_[a-z0-9_]+\z/D')
        ->and((string) config('database.connections.mysql.url'))
        ->toBe('')
        ->and(explode(',', (string) $session->sql_mode))
        ->toContain('STRICT_TRANS_TABLES')
        ->and($session->time_zone)
        ->toBe('+00:00')
        ->and($session->character_set_connection)
        ->toBe('utf8mb4')
        ->and($session->collation_connection)
        ->toStartWith('utf8mb4_')
        ->and($tableEngines)
        ->not->toBeEmpty()
        ->and($tableEngines->unique()->values()->all())
        ->toBe(['InnoDB'])
        ->and($oversizedIndexes)
        ->toBe(0)
        ->and($foreignKeys)
        ->toBeGreaterThan(0)
        ->and($sellMetricColumns)
        ->toBe([
            'id',
            'operation',
            'metrics_version',
            'selector_version',
            'algorithm_version',
            'scope_count',
            'candidate_count',
            'included_count',
            'excluded_count',
            'band_input_count',
            'outlier_count',
            'selection_replay_count',
            'price_band_replay_count',
            'scope_discovery_microseconds',
            'comparable_selection_microseconds',
            'selection_persistence_microseconds',
            'price_band_estimation_microseconds',
            'price_band_persistence_microseconds',
            'total_microseconds',
            'recorded_at',
        ])
        ->and($sellMetricIndexes)
        ->toContain(
            'PRIMARY',
            'sell_price_metrics_retention_index',
            'sell_price_metrics_report_index',
        )
        ->and($sellMetricForeignKeys)
        ->toBe(0)
        ->and($brokerOperations->attentionCount())
        ->toBe(0)
        ->and(array_keys($brokerOperations->counts))
        ->toBe([
            'requests_aging',
            'requests_past_needed_by',
            'offers_expired',
            'transactions_aging',
            'commissions_aging',
            'reports_overdue_purge',
            'payment_cases_aging',
        ])
        ->and(DB::table('migrations')->count())
        ->toBe($migrationFiles);

    expect(fn () => DB::table('comparable_items')
        ->orderBy('rank')
        ->limit(1)
        ->get())
        ->not->toThrow(Throwable::class);
});
