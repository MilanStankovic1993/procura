<?php

use Tests\Support\TestDatabaseSafety;

test('test databases remain fail closed outside explicitly isolated configurations', function (
    string $environment,
    string $connection,
    mixed $database,
    mixed $url,
    mixed $host,
    bool $mysqlEnabled,
    bool $allowed,
) {
    expect(TestDatabaseSafety::allows(
        environment: $environment,
        connection: $connection,
        database: $database,
        url: $url,
        host: $host,
        mysqlEnabled: $mysqlEnabled,
    ))->toBe($allowed);
})->with([
    'ordinary in-memory sqlite' => ['testing', 'sqlite', ':memory:', null, null, false, true],
    'sqlite file is rejected' => ['testing', 'sqlite', 'database/test.sqlite', null, null, false, false],
    'mysql needs the explicit switch' => ['testing', 'mysql', 'procura_ci_github', null, '127.0.0.1', false, false],
    'bounded local mysql is accepted' => ['testing', 'mysql', 'procura_ci_github', null, '127.0.0.1', true, true],
    'ordinary database name is rejected' => ['testing', 'mysql', 'procura', null, '127.0.0.1', true, false],
    'remote mysql is rejected' => ['testing', 'mysql', 'procura_ci_github', null, 'db.internal', true, false],
    'database URL cannot override the isolated database' => ['testing', 'mysql', 'procura_ci_github', 'mysql://remote/procura', '127.0.0.1', true, false],
    'production is always rejected' => ['production', 'mysql', 'procura_ci_github', null, '127.0.0.1', true, false],
]);
