<?php

namespace App\Jobs\Monitoring;

use App\Actions\Monitoring\EvaluateSavedSearchMatch;
use App\Models\ListingSnapshot;
use App\Models\SavedSearchVersion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchSavedSearch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $savedSearchVersionId,
        public readonly ?string $afterListingId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->savedSearchVersionId.':'.(
            $this->afterListingId ?? 'start'
        );
    }

    public function handle(EvaluateSavedSearchMatch $evaluator): void
    {
        $version = SavedSearchVersion::query()
            ->with('savedSearch')
            ->find($this->savedSearchVersionId);

        if (
            $version === null
            || $version->savedSearch->current_version_id
                !== $version->getKey()
            || ! $version->savedSearch->active
            || $version->savedSearch->archived_at !== null
        ) {
            return;
        }

        $limit = (int) config('monitoring.listing_chunk_size', 100);
        $snapshots = ListingSnapshot::query()
            ->whereHas(
                'listing',
                fn ($query) => $query->where(
                    'organization_id',
                    $version->organization_id,
                ),
            )
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('listing_snapshots as newer_snapshots')
                    ->whereColumn(
                        'newer_snapshots.listing_id',
                        'listing_snapshots.listing_id',
                    )
                    ->whereColumn(
                        'newer_snapshots.sequence',
                        '>',
                        'listing_snapshots.sequence',
                    );
            })
            ->when(
                $this->afterListingId,
                fn ($query) => $query->where(
                    'listing_snapshots.id',
                    '>',
                    $this->afterListingId,
                ),
            )
            ->orderBy('listing_snapshots.id')
            ->limit($limit)
            ->get();

        foreach ($snapshots as $snapshot) {
            $evaluator->evaluate($version, $snapshot);
        }

        if ($snapshots->count() === $limit) {
            self::dispatch(
                $version->getKey(),
                $snapshots->last()?->getKey(),
            );
        }
    }
}
