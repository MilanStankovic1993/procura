<?php

namespace App\Console\Commands;

use App\Actions\BrokerRequests\SettleBrokerCommission;
use App\Models\BrokerCommission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SettleBrokerCommissionCommand extends Command
{
    protected $signature = 'broker-commissions:settle
        {commission : Broker-commission ULID}
        {--actor-email= : Verified super-administrator email}
        {--expected-event= : Exact current commission-event ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--reason-code= : Lowercase operational reason code}
        {--evidence= : External commission-settlement evidence reference}';

    protected $description = 'Append one audited broker-commission settlement';

    public function handle(SettleBrokerCommission $action): int
    {
        $commission = BrokerCommission::query()->find(
            (string) $this->argument('commission'),
        );
        $actor = User::query()
            ->where(
                'email',
                Str::lower(trim((string) $this->option('actor-email'))),
            )
            ->first();

        if ($commission === null || $actor === null) {
            $this->components->error(
                'The broker commission or operator account was not found.',
            );

            return self::FAILURE;
        }

        try {
            $result = $action->execute(
                commission: $commission,
                actor: $actor,
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
            |ValidationException $exception
        ) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Broker commission %s is settled (%s event).',
            $commission->getKey(),
            $result->created ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }
}
