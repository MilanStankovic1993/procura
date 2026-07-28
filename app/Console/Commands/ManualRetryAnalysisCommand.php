<?php

namespace App\Console\Commands;

use App\Actions\Analyses\RequestManualAnalysisRetry;
use App\Exceptions\AnalysisRetryConflictException;
use App\Models\Analysis;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ManualRetryAnalysisCommand extends Command
{
    protected $signature = 'analyses:manual-retry
        {analysis : Failed analysis ULID}
        {--actor-email= : Verified super-administrator email}
        {--expected-dispatch= : Exact current dispatch ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--reason= : Reviewed operational retry reason}';

    protected $description = 'Create one audited manual retry run for a terminal failed analysis';

    public function handle(RequestManualAnalysisRetry $action): int
    {
        $analysis = Analysis::query()->find(
            trim((string) $this->argument('analysis')),
        );
        $operator = User::query()
            ->where(
                'email',
                Str::lower(trim((string) $this->option('actor-email'))),
            )
            ->first();

        if ($analysis === null || $operator === null) {
            $this->components->error(
                'The analysis or operator account was not found.',
            );

            return self::FAILURE;
        }

        try {
            $result = $action->request(
                analysis: $analysis,
                operator: $operator,
                expectedCurrentDispatchId: trim(
                    (string) $this->option('expected-dispatch'),
                ),
                idempotencyKey: trim(
                    (string) $this->option('idempotency'),
                ),
                reason: trim((string) $this->option('reason')),
            );
        } catch (
            AnalysisRetryConflictException
            |AuthorizationException
            |InvalidArgumentException
            |ValidationException $exception
        ) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Analysis %s manual retry run %d is %s (%s request).',
            $analysis->getKey(),
            $result['dispatch']->run_number,
            $result['dispatch']->status->value,
            $result['created'] ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }
}
