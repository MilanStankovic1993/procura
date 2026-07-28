<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $application = parent::createApplication();
        $configuration = $application->make('config');
        $connection = $configuration->get('database.default');
        $database = $configuration->get("database.connections.{$connection}.database");

        if (
            ! $application->environment('testing')
            || $connection !== 'sqlite'
            || $database !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Unsafe test database configuration detected. Procura tests must run in the '
                .'testing environment against sqlite :memory:. Run "php artisan optimize:clear" '
                .'before retrying.',
            );
        }

        return $application;
    }
}
