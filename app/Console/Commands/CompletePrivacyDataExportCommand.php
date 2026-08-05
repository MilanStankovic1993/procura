<?php

namespace App\Console\Commands;

use App\Actions\Privacy\CompletePrivacyDataExport;
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

final class CompletePrivacyDataExportCommand extends Command
{
    protected $signature = 'privacy-requests:complete-export
        {request : Approved data-export privacy-request ULID}
        {--actor-email= : Verified super-administrator email}
        {--expected-event= : Exact approved current event ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--inventory-version= : Exact approved data-inventory version}
        {--identity-evidence= : Identity-verification evidence reference}
        {--artifact-reference= : Private archive or secure-vault reference}
        {--artifact-sha256= : SHA-256 checksum of the delivered archive}
        {--artifact-size-bytes= : Exact delivered archive size}
        {--artifact-expires-at= : ISO-8601 artifact expiry time}
        {--delivery-evidence= : Secure-delivery evidence reference}
        {--note= : Operational completion explanation}';

    protected $description = 'Record an immutable, evidence-bound data-export fulfillment receipt';

    public function handle(CompletePrivacyDataExport $action): int
    {
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

        $size = filter_var(
            $this->option('artifact-size-bytes'),
            FILTER_VALIDATE_INT,
        );

        if ($size === false) {
            $this->components->error(
                'The artifact size must be a whole number of bytes.',
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
                artifactReference: trim(
                    (string) $this->option('artifact-reference'),
                ),
                artifactSha256: trim(
                    (string) $this->option('artifact-sha256'),
                ),
                artifactSizeBytes: $size,
                artifactExpiresAt: CarbonImmutable::parse(
                    trim(
                        (string) $this->option('artifact-expires-at'),
                    ),
                ),
                deliveryEvidenceReference: trim(
                    (string) $this->option('delivery-evidence'),
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
            'Data export %s is fulfilled with receipt %s (%s receipt).',
            $request->getKey(),
            $result['fulfillment']->getKey(),
            $result['receipt_created'] ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }
}
