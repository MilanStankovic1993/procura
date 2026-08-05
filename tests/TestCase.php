<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $application = parent::createApplication();
        $configuration = $application->make('config');
        $connection = $configuration->get('database.default');
        $connectionConfiguration = $configuration->get(
            "database.connections.{$connection}",
            [],
        );

        if (
            ! is_string($connection)
            || ! is_array($connectionConfiguration)
            || ! TestDatabaseSafety::allows(
                environment: (string) $application->environment(),
                connection: $connection,
                database: $connectionConfiguration['database'] ?? null,
                url: $connectionConfiguration['url'] ?? null,
                host: $connectionConfiguration['host'] ?? null,
                mysqlEnabled: (bool) $configuration->get(
                    'testing.mysql_enabled',
                    false,
                ),
            )
        ) {
            throw new \RuntimeException(
                'Unsafe test database configuration detected. Procura tests require testing with '
                .'sqlite :memory:, or explicit PROCURA_TEST_MYSQL_ENABLED=true with a local '
                .'procura_ci_* MySQL database and no DB_URL. Run "php artisan optimize:clear" '
                .'before retrying.',
            );
        }

        return $application;
    }
}
