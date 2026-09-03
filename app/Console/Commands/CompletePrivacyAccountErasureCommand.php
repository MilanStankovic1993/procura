<?php

namespace App\Console\Commands;

use App\Actions\Privacy\CompletePrivacyAccountErasure;
use App\Exceptions\PrivacyRequestConflictException;
use App\Models\PrivacyRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CompletePrivacyAccountErasureCommand extends Command
{
    protected $signature = 'privacy-requests:complete-erasure
        {request : Approved account-deletion privacy-request ULID}
        {--actor-email= : Verified super-administrator email}
        {--expected-event= : Exact approved current event ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--inventory-version= : Exact approved erasure-inventory version}
        {--identity-evidence= : Identity-verification evidence reference}
        {--erasure-evidence= : Isolation and erasure-run evidence reference}
        {--storage-evidence= : Private-storage cleanup evidence reference}
        {--processor-evidence= : External-processor cleanup evidence reference}
        {--completion-evidence= : Subject-safe completion receipt reference}
        {--clearance=* : Blocker clearance as blocker_code=evidence_reference}
        {--backup-purge-due-at= : ISO-8601 final backup-purge deadline}
        {--note= : Operational completion explanation}';

    protected $description = 'Execute and record an evidence-bound account erasure';

    public function handle(
        CompletePrivacyAccountErasure $action,
    ): int {
        $request = PrivacyRequest::query()->find(
            trim((string) $this->argument('request')),
        );
        $actor = User::query()
            ->where(
                'email',
                Str::lower(trim((string) $this->option('actor-email'))),
            )
            ->first();

        if ($request === null || $actor === null) {
            $this->components->error(
                'The privacy request or operator account was not found.',
            );

            return self::FAILURE;
        }

        try {
            $result = $action->complete(
                request: $request,
                operator: $actor,
                expectedCurrentEventId: trim(
                    (string) $this->option('expected-event'),
                ),
                idempotencyKey: trim(
                    (string) $this->option('idempotency'),
                ),
                dataInventoryVersion: trim(
                    (string) $this->option('inventory-version'),
                ),
                identityEvidenceReference: trim(
                    (string) $this->option('identity-evidence'),
                ),
                erasureEvidenceReference: trim(
                    (string) $this->option('erasure-evidence'),
                ),
                storageEvidenceReference: trim(
                    (string) $this->option('storage-evidence'),
                ),
                processorEvidenceReference: trim(
                    (string) $this->option('processor-evidence'),
                ),
                completionEvidenceReference: trim(
                    (string) $this->option('completion-evidence'),
                ),
                clearanceReferences: $this->clearanceReferences(),
                backupPurgeDueAt: CarbonImmutable::parse(
                    trim(
                        (string) $this->option(
                            'backup-purge-due-at',
                        ),
                    ),
                ),
                note: trim((string) $this->option('note')),
            );
        } catch (
            AuthorizationException
            |InvalidArgumentException
            |InvalidFormatException
            |PrivacyRequestConflictException
            |ValidationException $exception
        ) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Account deletion %s is fulfilled with receipt %s (%s receipt).',
            $request->getKey(),
            $result['fulfillment']->getKey(),
            $result['receipt_created'] ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function clearanceReferences(): array
    {
        $references = [];

        foreach ((array) $this->option('clearance') as $clearance) {
            $parts = explode('=', (string) $clearance, 2);

            if (
                count($parts) !== 2
                || trim($parts[0]) === ''
                || array_key_exists(trim($parts[0]), $references)
            ) {
                throw new InvalidArgumentException(
                    'Each --clearance must be unique and use blocker_code=evidence_reference.',
                );
            }

            $references[trim($parts[0])] = trim($parts[1]);
        }

        return $references;
    }
}
