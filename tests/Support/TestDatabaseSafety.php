<?php

namespace Tests\Support;

final class TestDatabaseSafety
{
    public static function allows(
        string $environment,
        string $connection,
        mixed $database,
        mixed $url,
        mixed $host,
        bool $mysqlEnabled,
    ): bool {
        if ($environment !== 'testing' || self::configured($url)) {
            return false;
        }

        if ($connection === 'sqlite') {
            return $database === ':memory:';
        }

        return $mysqlEnabled
            && $connection === 'mysql'
            && is_string($database)
            && preg_match('/\Aprocura_ci_[a-z0-9_]+\z/D', $database) === 1
            && is_string($host)
            && in_array(strtolower($host), ['127.0.0.1', 'localhost', 'mysql'], true);
    }

    private static function configured(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
