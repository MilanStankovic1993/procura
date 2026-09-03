<?php

namespace App\Actions\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Analyses\AnalysisType;
use App\Enums\Organizations\OrganizationPermission;
use App\Models\Analysis;
use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBuyAnalysisDraft
{
    public function __construct(private readonly AnalysisAuthorizer $authorizer) {}

    public function create(
        Organization $organization,
        User $actor,
        string $listingId,
        string $targetCountryCode,
    ): Analysis {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $listingId,
            $targetCountryCode,
        ): Analysis {
            $this->authorizer->authorize(
                $organization,
                $actor,
                OrganizationPermission::ManageAnalyses,
                lockForUpdate: true,
            );

            $listing = Listing::query()
                ->forOrganization($organization)
                ->with(['images' => static fn ($query) => $query->orderBy('kind')->orderBy('position')])
                ->lockForUpdate()
                ->findOrFail($listingId);
            $snapshot = ListingSnapshot::query()
                ->where('listing_id', $listing->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->firstOrFail();
            $payload = $this->requestPayload($listing, $snapshot, $targetCountryCode);
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $requestHash = hash('sha256', $encoded);
            $existing = Analysis::query()
                ->forOrganization($organization)
                ->where('listing_id', $listing->getKey())
                ->where('request_hash', $requestHash)
                ->where('status', AnalysisStatus::Draft)
                ->first();

            return $existing ?? Analysis::query()->create([
                'organization_id' => $organization->getKey(),
                'listing_id' => $listing->getKey(),
                'listing_snapshot_id' => $snapshot->getKey(),
                'requested_by_user_id' => $actor->getKey(),
                'analysis_type' => AnalysisType::Buy,
                'status' => AnalysisStatus::Draft,
                'source_country_code' => $listing->source_country_code,
                'target_country_code' => $targetCountryCode,
                'pipeline_version' => config('analyses.pipeline_version'),
                'request_payload' => $payload,
                'request_hash' => $requestHash,
            ]);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function requestPayload(
        Listing $listing,
        ListingSnapshot $snapshot,
        string $targetCountryCode,
    ): array {
        return [
            'schema_version' => 'buy-analysis-request:v1',
            'analysis_type' => AnalysisType::Buy->value,
            'listing' => [
                'id' => $listing->getKey(),
                'snapshot_id' => $snapshot->getKey(),
                'snapshot_sequence' => $snapshot->sequence,
                'snapshot_content_hash' => $snapshot->content_hash,
                'source_url' => $snapshot->source_url,
                'external_id' => $snapshot->external_id,
                'marketplace_name' => $snapshot->marketplace_name,
                'title' => $snapshot->title,
                'description' => $snapshot->description,
                'asking_price_minor' => $snapshot->asking_price_minor,
                'currency_code' => $snapshot->currency_code,
                'seller_information' => $snapshot->seller_information,
                'location' => $snapshot->location,
                'listing_status' => $snapshot->status->value,
                'captured_at' => $snapshot->captured_at?->toIso8601String(),
            ],
            'market_scope' => [
                'source_country_code' => $listing->source_country_code,
                'target_country_code' => $targetCountryCode,
            ],
            'evidence' => $listing->images->map(static fn ($image): array => [
                'id' => $image->getKey(),
                'kind' => $image->kind->value,
                'checksum_sha256' => $image->checksum_sha256,
                'mime_type' => $image->mime_type,
                'width' => $image->width,
                'height' => $image->height,
            ])->values()->all(),
        ];
    }
}
