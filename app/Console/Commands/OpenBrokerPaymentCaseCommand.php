<?php

namespace App\Console\Commands;

use App\Actions\BrokerRequests\OpenBrokerPaymentCase;
use App\Enums\BrokerRequests\BrokerPaymentCaseType;
use App\Models\BrokerTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ValueError;

final class OpenBrokerPaymentCaseCommand extends Command
{
    protected $signature = 'broker-payment-cases:open
        {transaction : Broker-transaction ULID}
        {type : refund or dispute}
        {--actor-email= : Verified super-administrator email}
        {--expected-transaction-event= : Exact current transaction-event ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--amount-minor= : Requested amount in transaction currency minor units}
        {--external-case= : Approved external support or provider case reference}
        {--reason-code= : Lowercase operational reason code}
        {--evidence= : External reviewed evidence reference}';

    protected $description = 'Open one audited broker refund or dispute case';

    public function handle(OpenBrokerPaymentCase $action): int
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
            $amount = filter_var(
                $this->option('amount-minor'),
                FILTER_VALIDATE_INT,
            );

            if ($amount === false) {
                throw new InvalidArgumentException(
                    'The payment-case amount must be an integer in minor units.',
                );
            }

            $result = $action->execute(
                transaction: $transaction,
                actor: $actor,
                type: BrokerPaymentCaseType::from(
                    (string) $this->argument('type'),
                ),
                requestedAmountMinor: $amount,
                expectedTransactionEventId: trim(
                    (string) $this->option('expected-transaction-event'),
                ),
                idempotencyKey: trim(
                    (string) $this->option('idempotency'),
                ),
                externalCaseReference: trim(
                    (string) $this->option('external-case'),
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
            'Broker payment case %s is open (%s event).',
            $result->paymentCase->getKey(),
            $result->created ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }
}
