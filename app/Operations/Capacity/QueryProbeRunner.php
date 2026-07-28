<?php

namespace App\Operations\Capacity;

use App\Operations\Capacity\Data\CapacityProbeResult;
use Closure;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class QueryProbeRunner
{
    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    /**
     * @param  Closure(): int  $probe
     * @param  array{
     *     maximum_queries: int,
     *     maximum_database_milliseconds: int,
     *     maximum_wall_milliseconds: int
     * }  $budget
     */
    public function run(
        string $name,
        Closure $probe,
        array $budget,
        bool $enforceDuration,
    ): CapacityProbeResult {
        $connection = $this->database->connection();
        $wasLogging = $connection->logging();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $startedAt = hrtime(true);
        $resultCount = null;
        $errorCode = null;

        try {
            $resultCount = $probe();
        } catch (Throwable $exception) {
            $errorCode = class_basename($exception);
        } finally {
            $wallMilliseconds = (
                (hrtime(true) - $startedAt) / 1_000_000
            );
            $queries = $connection->getQueryLog();
            $connection->flushQueryLog();

            if (! $wasLogging) {
                $connection->disableQueryLog();
            }
        }

        $databaseMilliseconds = (float) collect($queries)
            ->sum(
                static fn (array $query): float => (
                    (float) ($query['time'] ?? 0)
                ),
            );
        $queryCount = count($queries);
        $passed = (
            $errorCode === null
            && $queryCount <= $budget['maximum_queries']
            && (
                ! $enforceDuration
                || (
                    $databaseMilliseconds
                        <= $budget['maximum_database_milliseconds']
                    && $wallMilliseconds
                        <= $budget['maximum_wall_milliseconds']
                )
            )
        );

        return new CapacityProbeResult(
            name: $name,
            status: $passed ? 'passed' : (
                $errorCode === null ? 'failed' : 'error'
            ),
            queryCount: $queryCount,
            databaseMilliseconds: $databaseMilliseconds,
            wallMilliseconds: $wallMilliseconds,
            resultCount: $resultCount,
            budget: $budget,
            durationEnforced: $enforceDuration,
            errorCode: $errorCode,
        );
    }
}
