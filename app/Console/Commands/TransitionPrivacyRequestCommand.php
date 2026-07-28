<?php

namespace App\Console\Commands;

use App\Actions\Privacy\TransitionPrivacyRequest;
use App\Enums\Privacy\PrivacyRequestStatus;
use App\Exceptions\PrivacyRequestConflictException;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ValueError;

final class TransitionPrivacyRequestCommand extends Command
{
    protected $signature = 'privacy-requests:transition
        {request : Privacy-request ULID}
        {status : Target workflow status}
        {--actor-email= : Verified super-administrator email}
        {--expected-event= : Exact current event ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--reason-code= : Lowercase operational reason code}
        {--note= : Operational explanation recorded in the audit ledger}
        {--evidence= : External case, export, erasure, or rejection evidence reference}';

    protected $description = 'Append one audited privacy-request workflow transition';

    public function handle(TransitionPrivacyRequest $action): int
    {
        $request = PrivacyRequest::query()->find(
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
                'The privacy request or operator account was not found.',
            );

            return self::FAILURE;
        }

        try {
            $status = PrivacyRequestStatus::from(
                (string) $this->argument('status'),
            );
            $result = $action->transition(
                request: $request,
                operator: $actor,
                nextStatus: $status,
                expectedCurrentEventId: trim(
                    (string) $this->option('expected-event'),
                ),
                idempotencyKey: trim(
                    (string) $this->option('idempotency'),
                ),
                reasonCode: trim(
                    (string) $this->option('reason-code'),
                ),
                note: trim((string) $this->option('note')),
                evidenceReference: $this->nullableOption('evidence'),
            );
        } catch (
            AuthorizationException
            |InvalidArgumentException
            |PrivacyRequestConflictException
            |ValidationException
            |ValueError $exception
        ) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Privacy request %s is now %s (%s event).',
            $request->getKey(),
            $status->value,
            $result['event_created'] ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }

    private function nullableOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }
}
