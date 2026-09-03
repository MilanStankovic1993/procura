<?php

namespace App\Console\Commands;

use App\Actions\BrokerRequests\TransitionBrokerPaymentCase;
use App\Enums\BrokerRequests\BrokerPaymentCaseOutcome;
use App\Enums\BrokerRequests\BrokerPaymentCaseStatus;
use App\Models\BrokerPaymentCase;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ValueError;

final class TransitionBrokerPaymentCaseCommand extends Command
{
    protected $signature = 'broker-payment-cases:transition
        {payment-case : Broker payment-case ULID}
        {status : under_review, resolved, or cancelled}
        {--actor-email= : Verified super-administrator email}
        {--expected-event= : Exact current payment-case event ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--outcome= : Required type-compatible outcome for resolution}
        {--resolved-amount-minor= : Required resolved amount for resolution}
        {--reason-code= : Lowercase operational reason code}
        {--evidence= : External reviewed evidence reference}';

    protected $description = 'Append one audited broker payment-case transition';

    public function handle(TransitionBrokerPaymentCase $action): int
    {
        $case = BrokerPaymentCase::query()->find(
            (string) $this->argument('payment-case'),
        );
        $actor = User::query()
            ->where(
                'email',
                Str::lower(trim((string) $this->option('actor-email'))),
            )
            ->first();

        if ($case === null || $actor === null) {
            $this->components->error(
                'The broker payment case or operator account was not found.',
            );

            return self::FAILURE;
        }

        try {
            $outcomeValue = trim((string) $this->option('outcome'));
            $amountValue = $this->option('resolved-amount-minor');
            $amount = $amountValue === null
                ? null
                : filter_var($amountValue, FILTER_VALIDATE_INT);

            if ($amount === false) {
                throw new InvalidArgumentException(
                    'The resolved amount must be an integer in minor units.',
                );
            }

            $result = $action->execute(
                paymentCase: $case,
                actor: $actor,
                target: BrokerPaymentCaseStatus::from(
                    (string) $this->argument('status'),
                ),
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
                outcome: $outcomeValue === ''
                    ? null
                    : BrokerPaymentCaseOutcome::from($outcomeValue),
                resolvedAmountMinor: $amount,
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
            'Broker payment case %s is now %s (%s event).',
            $case->getKey(),
            $result->paymentCase->status->value,
            $result->created ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }
}
