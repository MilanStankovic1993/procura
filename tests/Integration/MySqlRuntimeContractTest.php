<?php

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
        ->where('table_schema', $database)
        ->where('table_type', 'BASE TABLE')
        ->pluck('engine');
    $oversizedIndexes = DB::table('information_schema.statistics')
        ->where('table_schema', $database)
        ->whereRaw('CHAR_LENGTH(index_name) > 64')
        ->count();
    $foreignKeys = DB::table('information_schema.referential_constraints')
        ->where('constraint_schema', $database)
        ->count();
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
        ->and(DB::table('migrations')->count())
        ->toBe($migrationFiles);

    expect(fn () => DB::table('comparable_items')
        ->orderBy('rank')
        ->limit(1)
        ->get())
        ->not->toThrow(Throwable::class);
});
