<?php

namespace App\Console\Commands;

use App\Actions\BrokerRequests\GenerateBrokerReport;
use App\Enums\Localization\SupportedLocale;
use App\Models\BrokerTransaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

final class GenerateBrokerReportCommand extends Command
{
    protected $signature = 'broker-reports:generate
        {transaction : Completed broker-transaction ULID}
        {--actor-email= : Verified super-administrator email}
        {--expected-transaction-event= : Exact current transaction-event ULID}
        {--expected-commission-event= : Exact current commission-event ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--locale=en : en, de, es, fr or sr-Latn}
        {--reason-code= : Lowercase operational reason code}
        {--evidence= : External report-generation evidence reference}';

    protected $description = 'Generate one immutable private broker report';

    public function handle(GenerateBrokerReport $action): int
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
        $locale = SupportedLocale::tryFrom(
            trim((string) $this->option('locale')),
        );

        if ($transaction === null || $actor === null || $locale === null) {
            $this->components->error(
                'The transaction, operator account or report locale is invalid.',
            );

            return self::FAILURE;
        }

        try {
            $result = $action->execute(
                transaction: $transaction,
                actor: $actor,
                expectedTransactionEventId: trim(
                    (string) $this->option('expected-transaction-event'),
                ),
                expectedCommissionEventId: trim(
                    (string) $this->option('expected-commission-event'),
                ),
                idempotencyKey: trim(
                    (string) $this->option('idempotency'),
                ),
                locale: $locale,
                reasonCode: trim(
                    (string) $this->option('reason-code'),
                ),
                evidenceReference: trim(
                    (string) $this->option('evidence'),
                ),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Broker report %s is available (%s generation).',
            $result->report->getKey(),
            $result->created ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }
}
