<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Api\ApiErrorCode;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Outcomes\ActualSaleOutcomeType;
use App\Enums\Sell\SalePortfolioEventType;
use App\Enums\Sell\SalePortfolioStatus;
use App\Exceptions\SalePortfolioConflictException;
use App\Models\ActualSale;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\SalePortfolioEntry;
use App\Models\SalePortfolioEvent;
use App\Models\User;
use App\SalePortfolio\SalePortfolioSourceEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class RecordSalePortfolioEvent
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly SalePortfolioSourceEvidence $sourceEvidence,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{entry: SalePortfolioEntry, event: SalePortfolioEvent, created: bool}
     *
     * @throws JsonException
     */
    public function record(
        Organization $organization,
        User $actor,
        string $ownedProductId,
        string $entryId,
        array $attributes,
    ): array {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $ownedProductId,
            $entryId,
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
            $entry = SalePortfolioEntry::query()
                ->forOrganization($organization)
                ->where('owned_product_id', $product->getKey())
                ->with(['ownedProduct', 'listingDraft.priceBand'])
                ->lockForUpdate()
                ->findOrFail($entryId);
            $payloadHash = $this->payloadHash(
                $organization,
                $actor,
                $entry,
                $attributes,
            );
            $idempotent = SalePortfolioEvent::query()
                ->forOrganization($organization)
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($idempotent !== null) {
                if (! hash_equals($idempotent->payload_hash, $payloadHash)) {
                    throw $this->idempotencyConflict();
                }

                return [
                    'entry' => $this->loadEntry($entry),
                    'event' => $this->loadEvent($idempotent),
                    'created' => false,
                ];
            }

            if (ActualSale::query()
                ->where('sale_portfolio_entry_id', $entry->getKey())
                ->where(
                    'outcome_type',
                    ActualSaleOutcomeType::Sold->value,
                )
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'sale_portfolio_entry_id' => [
                        'A portfolio lifecycle cannot change after a realized sale has been recorded.',
                    ],
                ]);
            }

            $current = SalePortfolioEvent::query()
                ->forOrganization($organization)
                ->where('sale_portfolio_entry_id', $entry->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $currentId = $current?->getKey();

            if ($attributes['expected_current_event_id'] !== $currentId) {
                throw new SalePortfolioConflictException(
                    ApiErrorCode::SalePortfolioStaleState,
                    'expected_current_event_id',
                    'The sale portfolio state changed. Refresh before recording another event.',
                );
            }

            $eventType = SalePortfolioEventType::from(
                $attributes['event_type'],
            );
            $priorStatus = $current?->next_status
                ?? SalePortfolioStatus::Draft;

            if (! SalePortfolioEventType::canApply(
                $priorStatus,
                $eventType,
            )) {
                throw ValidationException::withMessages([
                    'event_type' => [
                        sprintf(
                            'The %s event is not allowed while the portfolio entry is %s.',
                            $eventType->value,
                            $priorStatus->value,
                        ),
                    ],
                ]);
            }

            if (
                in_array($eventType, [
                    SalePortfolioEventType::Published,
                    SalePortfolioEventType::Relisted,
                ], true)
                && ! $this->sourceEvidence->matches(
                    $entry,
                    lockForUpdate: true,
                )
            ) {
                throw ValidationException::withMessages([
                    'sale_portfolio_entry_id' => [
                        'The listing-draft evidence is no longer current. Create a new ready portfolio entry before publishing.',
                    ],
                ]);
            }

            $occurredAt = CarbonImmutable::parse(
                $attributes['occurred_at'],
            )->utc();

            if (
                $current !== null
                && $occurredAt->lt($current->occurred_at)
            ) {
                throw ValidationException::withMessages([
                    'occurred_at' => [
                        'The event time cannot be earlier than the current portfolio event.',
                    ],
                ]);
            }

            $publication = $this->publicationSnapshot(
                $eventType,
                $current,
                $attributes,
            );
            $this->guardExternalIdentity(
                $organization,
                $entry,
                $publication,
            );
            $sequence = ($current?->sequence ?? 0) + 1;

            if ($sequence > (int) config(
                'sale_portfolio.maximum_events_per_entry',
            )) {
                throw ValidationException::withMessages([
                    'sale_portfolio_entry_id' => [
                        'The lifecycle history limit has been reached for this portfolio entry.',
                    ],
                ]);
            }

            $inputSnapshot = [
                'organization_id' => $organization->getKey(),
                'owned_product_id' => $product->getKey(),
                'sale_portfolio_entry_id' => $entry->getKey(),
                'sell_listing_draft_id' => $entry->sell_listing_draft_id,
                'listing_draft_input_hash' => (
                    $entry->listing_draft_input_hash
                ),
                'previous_event_id' => $currentId,
                'event_type' => $eventType->value,
                'prior_status' => $priorStatus->value,
                'next_status' => $eventType->nextStatus()->value,
                ...$publication,
                'reason_code' => $attributes['reason_code'] ?? null,
                'note' => $attributes['note'] ?? null,
                'occurred_at' => $occurredAt->toIso8601String(),
                'recording_method' => 'manual_user_entry',
            ];

            try {
                $event = SalePortfolioEvent::query()->create([
                    'organization_id' => $organization->getKey(),
                    'sale_portfolio_entry_id' => $entry->getKey(),
                    'previous_event_id' => $currentId,
                    'actor_user_id' => $actor->getKey(),
                    'sequence' => $sequence,
                    'event_type' => $eventType,
                    'prior_status' => $priorStatus,
                    'next_status' => $eventType->nextStatus(),
                    ...$publication,
                    'reason_code' => $attributes['reason_code'] ?? null,
                    'note' => $attributes['note'] ?? null,
                    'idempotency_key' => $attributes['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'input_snapshot' => $inputSnapshot,
                    'occurred_at' => $occurredAt,
                    'recorded_at' => now(),
                ]);
            } catch (QueryException $exception) {
                $collision = SalePortfolioEvent::query()
                    ->forOrganization($organization)
                    ->where(
                        'idempotency_key',
                        $attributes['idempotency_key'],
                    )
                    ->first();

                if ($collision === null) {
                    throw $exception;
                }

                if (! hash_equals($collision->payload_hash, $payloadHash)) {
                    throw $this->idempotencyConflict();
                }

                return [
                    'entry' => $this->loadEntry($entry),
                    'event' => $this->loadEvent($collision),
                    'created' => false,
                ];
            }

            return [
                'entry' => $this->loadEntry($entry),
                'event' => $this->loadEvent($event),
                'created' => true,
            ];
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function publicationSnapshot(
        SalePortfolioEventType $eventType,
        ?SalePortfolioEvent $current,
        array $attributes,
    ): array {
        if ($eventType->requiresPublicationSnapshot()) {
            return [
                'marketplace_name' => $attributes['marketplace_name'],
                'marketplace_key' => $attributes['marketplace_key'],
                'external_listing_id' => (
                    $attributes['external_listing_id']
                ),
                'external_listing_url' => (
                    $attributes['external_listing_url']
                ),
                'advertised_price_minor' => (
                    $attributes['advertised_price_minor']
                ),
                'advertised_currency_code' => (
                    $attributes['advertised_currency_code']
                ),
            ];
        }

        if ($current === null) {
            throw ValidationException::withMessages([
                'event_type' => [
                    'A publication event must be recorded before this lifecycle event.',
                ],
            ]);
        }

        return [
            'marketplace_name' => $current->marketplace_name,
            'marketplace_key' => $current->marketplace_key,
            'external_listing_id' => $current->external_listing_id,
            'external_listing_url' => $current->external_listing_url,
            'advertised_price_minor' => $eventType->requiresPrice()
                ? $attributes['advertised_price_minor']
                : $current->advertised_price_minor,
            'advertised_currency_code' => $eventType->requiresPrice()
                ? $attributes['advertised_currency_code']
                : $current->advertised_currency_code,
        ];
    }

    /**
     * @param  array<string, mixed>  $publication
     */
    private function guardExternalIdentity(
        Organization $organization,
        SalePortfolioEntry $entry,
        array $publication,
    ): void {
        $usedByAnotherEntry = SalePortfolioEvent::query()
            ->forOrganization($organization)
            ->where(
                'marketplace_key',
                $publication['marketplace_key'],
            )
            ->where(
                'external_listing_id',
                $publication['external_listing_id'],
            )
            ->where(
                'sale_portfolio_entry_id',
                '!=',
                $entry->getKey(),
            )
            ->lockForUpdate()
            ->first() !== null;

        if ($usedByAnotherEntry) {
            throw ValidationException::withMessages([
                'external_listing_id' => [
                    'This marketplace listing identity is already attached to another sale portfolio entry.',
                ],
            ]);
        }
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

    private function loadEvent(
        SalePortfolioEvent $event,
    ): SalePortfolioEvent {
        return $event->load('actor:id,name');
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    private function payloadHash(
        Organization $organization,
        User $actor,
        SalePortfolioEntry $entry,
        array $attributes,
    ): string {
        return hash('sha256', json_encode([
            'organization_id' => $organization->getKey(),
            'sale_portfolio_entry_id' => $entry->getKey(),
            'actor_user_id' => $actor->getKey(),
            'expected_current_event_id' => (
                $attributes['expected_current_event_id']
            ),
            'event_type' => $attributes['event_type'],
            'marketplace_name' => $attributes['marketplace_name'] ?? null,
            'marketplace_key' => $attributes['marketplace_key'] ?? null,
            'external_listing_id' => (
                $attributes['external_listing_id'] ?? null
            ),
            'external_listing_url' => (
                $attributes['external_listing_url'] ?? null
            ),
            'advertised_price_minor' => (
                $attributes['advertised_price_minor'] ?? null
            ),
            'advertised_currency_code' => (
                $attributes['advertised_currency_code'] ?? null
            ),
            'reason_code' => $attributes['reason_code'] ?? null,
            'note' => $attributes['note'] ?? null,
            'occurred_at' => CarbonImmutable::parse(
                $attributes['occurred_at'],
            )->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
