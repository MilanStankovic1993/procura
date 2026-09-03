<?php

namespace App\Actions\Monitoring;

use App\Enums\Subscriptions\FeatureCode;
use App\Jobs\Monitoring\MatchSavedSearch;
use App\Models\Organization;
use App\Models\SavedSearch;
use App\Models\SavedSearchVersion;
use App\Models\User;
use App\Monitoring\Data\SavedSearchWriteResult;
use App\Monitoring\SavedSearchCriteriaNormalizer;
use App\Monitoring\SavedSearchCriteriaValidator;
use App\Monitoring\SavedSearchNotificationEntitlements;
use App\Subscriptions\SubscriptionEntitlements;
use App\Subscriptions\UsageLimitExceeded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateSavedSearch
{
    public function __construct(
        private readonly SavedSearchCriteriaNormalizer $normalizer,
        private readonly SavedSearchCriteriaValidator $criteriaValidator,
        private readonly SavedSearchNotificationEntitlements $notificationEntitlements,
        private readonly SubscriptionEntitlements $entitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(
        Organization $organization,
        User $actor,
        array $input,
        string $reasonCode,
        string $idempotencyKey,
    ): SavedSearchWriteResult {
        $criteria = $this->normalizer->normalize($input);
        $criteriaHash = $this->hash($criteria);
        $payloadHash = $this->hash([
            'criteria' => $criteria,
            'reason_code' => $reasonCode,
        ]);
        $this->criteriaValidator->validate($criteria);
        $this->notificationEntitlements->validate(
            $organization,
            $criteria['notification_channels'],
            recipient: $actor,
        );

        $result = DB::transaction(function () use (
            $organization,
            $actor,
            $criteria,
            $criteriaHash,
            $payloadHash,
            $reasonCode,
            $idempotencyKey,
        ): SavedSearchWriteResult {
            $existing = SavedSearchVersion::query()
                ->forOrganization($organization)
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

            Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertWithinLimit($organization);

            $savedSearch = SavedSearch::query()->forceCreate([
                'organization_id' => $organization->getKey(),
                'owner_user_id' => $actor->getKey(),
                'title' => $criteria['title'],
                'active' => $criteria['active'],
                'version_sequence' => 0,
            ]);
            $version = $savedSearch->versions()->create([
                ...$criteria,
                'organization_id' => $organization->getKey(),
                'changed_by_user_id' => $actor->getKey(),
                'sequence' => 1,
                'reason_code' => $reasonCode,
                'idempotency_key' => $idempotencyKey,
                'criteria_hash' => $criteriaHash,
                'payload_hash' => $payloadHash,
                'criteria_snapshot' => $criteria,
                'created_at' => now(),
            ]);
            $savedSearch->forceFill([
                'current_version_id' => $version->getKey(),
                'version_sequence' => 1,
            ])->save();

            DB::afterCommit(
                static fn () => MatchSavedSearch::dispatch($version->getKey()),
            );

            return new SavedSearchWriteResult(
                savedSearch: $savedSearch,
                version: $version,
                created: true,
            );
        }, attempts: 3);

        return $result;
    }

    private function assertWithinLimit(Organization $organization): void
    {
        $feature = $this->entitlements->feature(
            $organization,
            FeatureCode::SavedSearches,
        );
        $used = SavedSearch::query()
            ->forOrganization($organization)
            ->whereNull('archived_at')
            ->count();
        $limit = $feature->is_enabled ? $feature->limit : 0;

        if ($limit !== null && $used >= $limit) {
            throw new UsageLimitExceeded(
                FeatureCode::SavedSearches,
                $limit,
                $used,
                1,
            );
        }
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
