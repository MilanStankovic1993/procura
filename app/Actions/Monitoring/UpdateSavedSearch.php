<?php

namespace App\Actions\Monitoring;

use App\Enums\Validation\ApplicationValidationCode;
use App\Jobs\Monitoring\MatchSavedSearch;
use App\Models\SavedSearch;
use App\Models\SavedSearchVersion;
use App\Models\User;
use App\Monitoring\Data\SavedSearchWriteResult;
use App\Monitoring\SavedSearchCriteriaNormalizer;
use App\Monitoring\SavedSearchCriteriaValidator;
use App\Monitoring\SavedSearchNotificationEntitlements;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UpdateSavedSearch
{
    public function __construct(
        private readonly SavedSearchCriteriaNormalizer $normalizer,
        private readonly SavedSearchCriteriaValidator $criteriaValidator,
        private readonly SavedSearchNotificationEntitlements $notificationEntitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(
        SavedSearch $savedSearch,
        User $actor,
        array $input,
        string $expectedCurrentVersionId,
        string $reasonCode,
        string $idempotencyKey,
        bool $archive = false,
    ): SavedSearchWriteResult {
        return DB::transaction(function () use (
            $savedSearch,
            $actor,
            $input,
            $expectedCurrentVersionId,
            $reasonCode,
            $idempotencyKey,
            $archive,
        ): SavedSearchWriteResult {
            $locked = SavedSearch::query()
                ->forOrganization($savedSearch->organization_id)
                ->lockForUpdate()
                ->findOrFail($savedSearch->getKey());
            $current = SavedSearchVersion::query()
                ->whereKey($locked->current_version_id)
                ->lockForUpdate()
                ->firstOrFail();
            $rawCriteria = $archive
                ? [...$current->criteria_snapshot, 'active' => false]
                : $input;
            $criteria = $this->normalizer->normalize($rawCriteria);
            $this->criteriaValidator->validate($criteria);
            $this->notificationEntitlements->validate(
                $locked->organization()->firstOrFail(),
                $criteria['notification_channels'],
                $current->notification_channels,
                $locked->owner()->firstOrFail(),
            );
            $criteriaHash = $this->hash($criteria);
            $payloadHash = $this->hash([
                'criteria' => $criteria,
                'expected_current_version_id' => $expectedCurrentVersionId,
                'reason_code' => $reasonCode,
                'archive' => $archive,
            ]);
            $existing = SavedSearchVersion::query()
                ->forOrganization($locked->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertReplay($existing, $payloadHash);

                return new SavedSearchWriteResult(
                    savedSearch: $existing->savedSearch()->firstOrFail(),
                    version: $existing,
                    created: false,
                );
            }

            if ($current->getKey() !== $expectedCurrentVersionId) {
                ApplicationValidation::fail(
                    'expected_current_version_id',
                    ApplicationValidationCode::SavedSearchStale,
                );
            }

            if ($locked->archived_at !== null) {
                if ($archive) {
                    return new SavedSearchWriteResult(
                        savedSearch: $locked,
                        version: $current,
                        created: false,
                    );
                }

                ApplicationValidation::fail(
                    'saved_search',
                    ApplicationValidationCode::SavedSearchArchived,
                );
            }

            if ($criteriaHash === $current->criteria_hash && ! $archive) {
                return new SavedSearchWriteResult(
                    savedSearch: $locked,
                    version: $current,
                    created: false,
                );
            }

            $sequence = $locked->version_sequence + 1;
            $version = $locked->versions()->create([
                ...$criteria,
                'organization_id' => $locked->organization_id,
                'previous_version_id' => $current->getKey(),
                'changed_by_user_id' => $actor->getKey(),
                'sequence' => $sequence,
                'reason_code' => $reasonCode,
                'idempotency_key' => $idempotencyKey,
                'criteria_hash' => $criteriaHash,
                'payload_hash' => $payloadHash,
                'criteria_snapshot' => $criteria,
                'created_at' => now(),
            ]);
            $locked->forceFill([
                'title' => $criteria['title'],
                'active' => $criteria['active'],
                'current_version_id' => $version->getKey(),
                'version_sequence' => $sequence,
                'archived_at' => $archive ? now() : null,
            ])->save();

            if (! $archive && $criteria['active']) {
                DB::afterCommit(
                    static fn () => MatchSavedSearch::dispatch(
                        $version->getKey(),
                    ),
                );
            }

            return new SavedSearchWriteResult(
                savedSearch: $locked,
                version: $version,
                created: true,
            );
        }, attempts: 3);
    }

    private function assertReplay(
        SavedSearchVersion $version,
        string $payloadHash,
    ): void {
        Validator::make(
            ['payload_hash' => $payloadHash],
            ['payload_hash' => ['in:'.$version->payload_hash]],
            [
                'payload_hash.in' => 'The idempotency key was already used with different saved-search input.',
            ],
        )->validate();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function hash(array $value): string
    {
        return hash(
            'sha256',
            json_encode(
                $value,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
}
