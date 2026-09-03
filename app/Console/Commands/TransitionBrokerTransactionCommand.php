<?php

namespace App\Console\Commands;

use App\Actions\BrokerRequests\TransitionBrokerTransaction;
use App\Enums\BrokerRequests\BrokerTransactionStatus;
use App\Models\BrokerTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ValueError;

final class TransitionBrokerTransactionCommand extends Command
{
    protected $signature = 'broker-transactions:transition
        {transaction : Broker-transaction ULID}
        {status : Target workflow status}
        {--actor-email= : Verified super-administrator email}
        {--expected-event= : Exact current transaction-event ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--reason-code= : Lowercase operational reason code}
        {--evidence= : External payment or fulfillment evidence reference}';

    protected $description = 'Append one audited broker-transaction transition';

    public function handle(TransitionBrokerTransaction $action): int
    {
        $transaction = BrokerTransaction::query()->find(
            (string) $this->argument('transaction'),
        );
        $actor = User::query()
            ->where(
                'email',
                Str::lower(trim((string) $this->option('actor-email'))),
            )
            ->first();

        if ($transaction === null || $actor === null) {
            $this->components->error(
                'The broker transaction or operator account was not found.',
            );

            return self::FAILURE;
        }

        try {
            $status = BrokerTransactionStatus::from(
                (string) $this->argument('status'),
            );
            $result = $action->execute(
                transaction: $transaction,
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
            'Broker transaction %s is now %s (%s event).',
            $transaction->getKey(),
            $status->value,
            $result->created ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }
}
