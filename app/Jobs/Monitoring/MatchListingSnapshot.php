<?php

namespace App\Jobs\Monitoring;

use App\Actions\Monitoring\EvaluateSavedSearchMatch;
use App\Models\ListingSnapshot;
use App\Models\SavedSearchVersion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchListingSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $listingSnapshotId,
        public readonly ?string $afterSavedSearchVersionId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->listingSnapshotId.':'.(
            $this->afterSavedSearchVersionId ?? 'start'
        );
    }

    public function handle(EvaluateSavedSearchMatch $evaluator): void
    {
        $snapshot = ListingSnapshot::query()
            ->with('listing')
            ->find($this->listingSnapshotId);

        if ($snapshot === null) {
            return;
        }

        $limit = (int) config('monitoring.saved_search_chunk_size', 100);
        $versions = SavedSearchVersion::query()
            ->select('saved_search_versions.*')
            ->join(
                'saved_searches',
                'saved_searches.current_version_id',
                '=',
                'saved_search_versions.id',
            )
            ->where(
                'saved_searches.organization_id',
                $snapshot->listing->organization_id,
            )
            ->whereNull('saved_searches.archived_at')
            ->where('saved_searches.active', true)
            ->when(
                $this->afterSavedSearchVersionId,
                fn ($query) => $query->where(
                    'saved_search_versions.id',
                    '>',
                    $this->afterSavedSearchVersionId,
                ),
            )
            ->with('savedSearch')
            ->orderBy('saved_search_versions.id')
            ->limit($limit)
            ->get();

        foreach ($versions as $version) {
            $evaluator->evaluate($version, $snapshot);
        }

        if ($versions->count() === $limit) {
            self::dispatch(
                $snapshot->getKey(),
                $versions->last()?->getKey(),
            );
        }
    }
}
