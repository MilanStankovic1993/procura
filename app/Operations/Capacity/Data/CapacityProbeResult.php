<?php

namespace App\Operations\Capacity\Data;

final readonly class CapacityProbeResult
{
    /**
     * @param  array{
     *     maximum_queries: int,
     *     maximum_database_milliseconds: int,
     *     maximum_wall_milliseconds: int
     * }  $budget
     */
    public function __construct(
        public string $name,
        public string $status,
        public int $queryCount,
        public float $databaseMilliseconds,
        public float $wallMilliseconds,
        public ?int $resultCount,
        public array $budget,
        public bool $durationEnforced,
        public ?string $errorCode = null,
    ) {}

    /**
     * @param  array{
     *     maximum_queries: int,
     *     maximum_database_milliseconds: int,
     *     maximum_wall_milliseconds: int
     * }  $budget
     */
    public static function skipped(string $name, array $budget): self
    {
        return new self(
            name: $name,
            status: 'skipped',
            queryCount: 0,
            databaseMilliseconds: 0,
            wallMilliseconds: 0,
            resultCount: null,
            budget: $budget,
            durationEnforced: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'query_count' => $this->queryCount,
            'database_milliseconds' => round(
                $this->databaseMilliseconds,
                3,
            ),
            'wall_milliseconds' => round(
                $this->wallMilliseconds,
                3,
            ),
            'result_count' => $this->resultCount,
            'budget' => $this->budget,
            'duration_enforced' => $this->durationEnforced,
            'error_code' => $this->errorCode,
        ];
    }
}
