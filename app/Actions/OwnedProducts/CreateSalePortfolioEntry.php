<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Api\ApiErrorCode;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\OwnedProducts\OwnedProductStatus;
use App\Exceptions\SalePortfolioConflictException;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\SalePortfolioEntry;
use App\Models\SellListingDraft;
use App\Models\User;
use App\SalePortfolio\SalePortfolioSourceEvidence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class CreateSalePortfolioEntry
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly SalePortfolioSourceEvidence $sourceEvidence,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{entry: SalePortfolioEntry, created: bool}
     *
     * @throws JsonException
     */
    public function create(
        Organization $organization,
        User $actor,
        string $ownedProductId,
        array $attributes,
    ): array {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $ownedProductId,
            $attributes,
        ): array {
            $this->authorizer->authorize(
                $organization,
                $actor,
                OrganizationPermission::ManageOwnedProducts,
                lockForUpdate: true,
            );
            $product = OwnedProduct::query()
                ->forOrganization($organization)
                ->lockForUpdate()
                ->findOrFail($ownedProductId);

            if ($product->status !== OwnedProductStatus::Ready) {
                throw ValidationException::withMessages([
                    'owned_product' => [
                        'The owned product must be ready before it enters the sale portfolio.',
                    ],
                ]);
            }

            $payloadHash = $this->payloadHash(
                $organization,
                $actor,
                $product,
                $attributes,
            );
            $idempotent = SalePortfolioEntry::query()
                ->forOrganization($organization)
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($idempotent !== null) {
                return $this->idempotentResult($idempotent, $payloadHash);
            }

            $draft = SellListingDraft::query()
                ->forOrganization($organization)
                ->where('owned_product_id', $product->getKey())
                ->with('priceBand')
                ->lockForUpdate()
                ->findOrFail($attributes['sell_listing_draft_id']);
            $existing = SalePortfolioEntry::query()
                ->forOrganization($organization)
                ->where('sell_listing_draft_id', $draft->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [
                    'entry' => $this->loadEntry($existing),
                    'created' => false,
                ];
            }

            if (! $this->sourceEvidence->matches($this->candidate(
                $organization,
                $product,
                $draft,
            ), lockForUpdate: true)) {
                throw ValidationException::withMessages([
                    'sell_listing_draft_id' => [
                        'A current ready listing draft with completed photo review is required.',
                    ],
                ]);
            }

            $sequence = ((int) SalePortfolioEntry::query()
                ->where('owned_product_id', $product->getKey())
                ->max('sequence')) + 1;

            if ($sequence > (int) config(
                'sale_portfolio.maximum_entries_per_product',
            )) {
                throw ValidationException::withMessages([
                    'owned_product' => [
                        'The sale portfolio history limit has been reached for this product.',
                    ],
                ]);
            }

            $inputSnapshot = [
                'organization_id' => $organization->getKey(),
                'owned_product_id' => $product->getKey(),
                'sell_listing_draft_id' => $draft->getKey(),
                'listing_draft_input_hash' => $draft->input_hash,
                'assessment_input_hash' => $draft->assessment_input_hash,
                'price_band_input_hash' => $draft->price_band_input_hash,
                'image_evidence_hash' => $draft->image_evidence_hash,
                'target_country_code' => $draft->target_country_code,
                'target_currency_code' => $draft->target_currency_code,
                'listing_language' => $draft->listing_language,
                'price_strategy' => $draft->price_strategy->value,
                'initial_asking_price_minor' => (
                    $draft->target_asking_price_minor
                ),
                'draft_source_identifiers' => (
                    $draft->source_fact_identifiers
                ),
                'draft_input_snapshot' => $draft->input_snapshot,
            ];
            $entryKey = hash('sha256', implode('|', [
                $organization->getKey(),
                $product->getKey(),
                $draft->getKey(),
                $draft->input_hash,
            ]));

            try {
                $entry = SalePortfolioEntry::query()->create([
                    'organization_id' => $organization->getKey(),
                    'owned_product_id' => $product->getKey(),
                    'sell_listing_draft_id' => $draft->getKey(),
                    'created_by_user_id' => $actor->getKey(),
                    'sequence' => $sequence,
                    'listing_draft_input_hash' => $draft->input_hash,
                    'assessment_input_hash' => $draft->assessment_input_hash,
                    'price_band_input_hash' => $draft->price_band_input_hash,
                    'image_evidence_hash' => $draft->image_evidence_hash,
                    'entry_key' => $entryKey,
                    'payload_hash' => $payloadHash,
                    'idempotency_key' => $attributes['idempotency_key'],
                    'target_country_code' => $draft->target_country_code,
                    'target_currency_code' => $draft->target_currency_code,
                    'listing_language' => $draft->listing_language,
                    'price_strategy' => $draft->price_strategy,
                    'initial_asking_price_minor' => (
                        $draft->target_asking_price_minor
                    ),
                    'source_identifiers' => (
                        $draft->source_fact_identifiers
                    ),
                    'input_snapshot' => $inputSnapshot,
                    'entered_at' => now(),
                ]);
            } catch (QueryException $exception) {
                $collision = SalePortfolioEntry::query()
                    ->forOrganization($organization)
                    ->where(function ($query) use (
                        $attributes,
                        $draft,
                    ): void {
                        $query
                            ->where(
                                'idempotency_key',
                                $attributes['idempotency_key'],
                            )
                            ->orWhere(
                                'sell_listing_draft_id',
                                $draft->getKey(),
                            );
                    })
                    ->first();

                if ($collision === null) {
                    throw $exception;
                }

                if (
                    $collision->idempotency_key
                        === $attributes['idempotency_key']
                    && $collision->payload_hash !== $payloadHash
                ) {
                    throw $this->idempotencyConflict();
                }

                return [
                    'entry' => $this->loadEntry($collision),
                    'created' => false,
                ];
            }

            return [
                'entry' => $this->loadEntry($entry),
                'created' => true,
            ];
        }, attempts: 3);
    }

    private function candidate(
        Organization $organization,
        OwnedProduct $product,
        SellListingDraft $draft,
    ): SalePortfolioEntry {
        $entry = new SalePortfolioEntry([
            'organization_id' => $organization->getKey(),
            'owned_product_id' => $product->getKey(),
            'sell_listing_draft_id' => $draft->getKey(),
            'listing_draft_input_hash' => $draft->input_hash,
            'assessment_input_hash' => $draft->assessment_input_hash,
            'price_band_input_hash' => $draft->price_band_input_hash,
            'image_evidence_hash' => $draft->image_evidence_hash,
        ]);
        $entry->setRelation('ownedProduct', $product);
        $entry->setRelation('listingDraft', $draft);

        return $entry;
    }

    /**
     * @return array{entry: SalePortfolioEntry, created: false}
     */
    private function idempotentResult(
        SalePortfolioEntry $entry,
        string $payloadHash,
    ): array {
        if (! hash_equals($entry->payload_hash, $payloadHash)) {
            throw $this->idempotencyConflict();
        }

        return [
            'entry' => $this->loadEntry($entry),
            'created' => false,
        ];
    }

    private function idempotencyConflict(): SalePortfolioConflictException
    {
        return new SalePortfolioConflictException(
            ApiErrorCode::SalePortfolioIdempotencyConflict,
            'idempotency_key',
            'The idempotency key was already used with a different sale portfolio command.',
        );
    }

    private function loadEntry(
        SalePortfolioEntry $entry,
    ): SalePortfolioEntry {
        return $entry->load([
            'createdBy:id,name',
            'listingDraft',
            'currentEvent.actor:id,name',
            'currentActualSale',
            'events.actor:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    private function payloadHash(
        Organization $organization,
        User $actor,
        OwnedProduct $product,
        array $attributes,
    ): string {
        return hash('sha256', json_encode([
            'organization_id' => $organization->getKey(),
            'owned_product_id' => $product->getKey(),
            'sell_listing_draft_id' => (
                $attributes['sell_listing_draft_id']
            ),
            'actor_user_id' => $actor->getKey(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
