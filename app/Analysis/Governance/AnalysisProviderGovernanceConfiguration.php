<?php

namespace App\Analysis\Governance;

use RuntimeException;
use Throwable;

final class AnalysisProviderGovernanceConfiguration
{
    /** @return array{task: int, global: int, organization: int, user: int} */
    public function budgets(): array
    {
        $budgets = [
            'task' => $this->integer('task_max_cost_minor', 1, 1_000_000),
            'global' => $this->integer('global_monthly_budget_minor', 1, 1_000_000_000),
            'organization' => $this->integer('organization_monthly_budget_minor', 1, 1_000_000_000),
            'user' => $this->integer('user_monthly_budget_minor', 1, 1_000_000_000),
        ];

        if (
            $budgets['user'] > $budgets['organization']
            || $budgets['organization'] > $budgets['global']
            || $budgets['task'] > $budgets['user']
        ) {
            throw new RuntimeException('The analysis provider budget hierarchy is invalid.');
        }

        return $budgets;
    }

    /** @return array{failure_threshold: int, cooldown_seconds: int} */
    public function circuit(): array
    {
        return [
            'failure_threshold' => $this->integer('circuit_failure_threshold', 1, 100),
            'cooldown_seconds' => $this->integer('circuit_cooldown_seconds', 30, 86_400),
        ];
    }

    public function assertValid(): void
    {
        $this->budgets();
        $this->circuit();
    }

    public function isValid(): bool
    {
        try {
            $this->assertValid();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function integer(string $key, int $minimum, int $maximum): int
    {
        $value = config("analyses.provider_governance.{$key}");

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("The analysis provider {$key} configuration is invalid.");
        }

        return $value;
    }
}
