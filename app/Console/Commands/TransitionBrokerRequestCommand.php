<?php

namespace App\Console\Commands;

use App\Actions\BrokerRequests\TransitionBrokerRequest;
use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Models\BrokerRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ValueError;

final class TransitionBrokerRequestCommand extends Command
{
    protected $signature = 'broker-requests:transition
        {request : Broker-request ULID}
        {status : Target workflow status}
        {--actor-email= : Verified super-administrator email}
        {--expected-event= : Exact current event ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--reason-code= : Lowercase operational reason code}
        {--evidence= : External case or operator-work evidence reference}';

    protected $description = 'Append one audited operator broker-request transition';

    public function handle(TransitionBrokerRequest $action): int
    {
        $request = BrokerRequest::query()->find(
            (string) $this->argument('request'),
        );
        $actor = User::query()
            ->where(
                'email',
                Str::lower(trim((string) $this->option('actor-email'))),
            )
            ->first();

        if ($request === null || $actor === null) {
            $this->components->error(
                'The broker request or operator account was not found.',
            );

            return self::FAILURE;
        }

        try {
            $status = BrokerRequestStatus::from(
                (string) $this->argument('status'),
            );
            $result = $action->operatorTransition(
                request: $request,
                actor: $actor,
                target: $status,
                expectedCurrentEventId: trim(
                    (string) $this->option('expected-event'),
                ),
                idempotencyKey: trim(
                    (string) $this->option('idempotency'),
                ),
                reasonCode: trim(
                    (string) $this->option('reason-code'),
                ),
                evidenceReference: trim(
                    (string) $this->option('evidence'),
                ),
            );
        } catch (
            AuthorizationException
            |InvalidArgumentException
            |ValidationException
            |ValueError $exception
        ) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Broker request %s is now %s (%s event).',
            $request->getKey(),
            $status->value,
            $result->created ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }
}
