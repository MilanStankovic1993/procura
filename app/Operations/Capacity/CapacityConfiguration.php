<?php

namespace App\Operations\Capacity;

use RuntimeException;

final class CapacityConfiguration
{
    public function tenantPageSize(): int
    {
        $pageSize = config(
            'performance.capacity_baseline.tenant_page_size',
        );

        if (! is_int($pageSize) || $pageSize < 1 || $pageSize > 50) {
            throw new RuntimeException(
                'The capacity baseline tenant page size is invalid.',
            );
        }

        return $pageSize;
    }

    /**
     * @return array{
     *     maximum_queries: int,
     *     maximum_database_milliseconds: int,
     *     maximum_wall_milliseconds: int
     * }
     */
    public function budget(string $probe): array
    {
        $budget = config(
            "performance.capacity_baseline.probes.{$probe}",
        );

        if (! is_array($budget)) {
            throw new RuntimeException(
                "The {$probe} capacity budget is missing.",
            );
        }

        foreach ([
            'maximum_queries',
            'maximum_database_milliseconds',
            'maximum_wall_milliseconds',
        ] as $key) {
            if (
                ! is_int($budget[$key] ?? null)
                || $budget[$key] < 1
            ) {
                throw new RuntimeException(
                    "The {$probe} {$key} capacity budget is invalid.",
                );
            }
        }

        return [
            'maximum_queries' => $budget['maximum_queries'],
            'maximum_database_milliseconds' => (
                $budget['maximum_database_milliseconds']
            ),
            'maximum_wall_milliseconds' => (
                $budget['maximum_wall_milliseconds']
            ),
        ];
    }
}
