<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Api\ApiErrorCode;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Outcomes\ActualSaleOutcomeType;
use App\Enums\Sell\SalePortfolioEventType;
use App\Enums\Sell\SalePortfolioStatus;
use App\Exceptions\OutcomeTrackingConflictException;
use App\Models\ActualSale;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\SalePortfolioEntry;
use App\Models\SalePortfolioEvent;
use App\Models\User;
use App\OutcomeTracking\OutcomeMoneyNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class RecordActualSale
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly OutcomeMoneyNormalizer $money,
        private readonly RecordRealizedProfit $profits,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{sale: ActualSale, created: bool}
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
                ->with('events')
                ->lockForUpdate()
                ->findOrFail($entryId);
            $payloadHash = $this->payloadHash(
                $organization,
                $actor,
                $entry,
                $attributes,
            );
            $idempotent = ActualSale::query()
                ->forOrganization($organization)
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($idempotent !== null) {
                return $this->idempotent($idempotent, $payloadHash);
            }

            $currentSale = ActualSale::query()
                ->where('sale_portfolio_entry_id', $entry->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $this->guardHead(
                $attributes['expected_current_sale_id'],
                $currentSale,
                $attributes['correction_reason'] ?? null,
            );
            $currentEvent = SalePortfolioEvent::query()
                ->where('sale_portfolio_entry_id', $entry->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();

            if (
                $currentEvent === null
                || $attributes['sale_portfolio_event_id']
                    !== $currentEvent->getKey()
            ) {
                throw new OutcomeTrackingConflictException(
                    ApiErrorCode::ActualSaleStalePortfolioState,
                    'sale_portfolio_event_id',
                    'The sale portfolio state changed. Refresh before recording the outcome.',
                );
            }

            $outcomeType = ActualSaleOutcomeType::from(
                $attributes['outcome_type'],
            );
            $this->guardOutcome(
                $product,
                $entry,
                $currentSale,
                $currentEvent,
                $outcomeType,
                $attributes,
            );
            $occurredAt = CarbonImmutable::parse(
                $attributes['occurred_at'],
            )->utc();

            if ($occurredAt->lt($currentEvent->occurred_at)) {
                throw ValidationException::withMessages([
                    'occurred_at' => [
                        'The outcome time cannot be earlier than the portfolio event it confirms.',
                    ],
                ]);
            }

            $listedAt = $this->listedAt($entry, $currentEvent);

            if ($occurredAt->lt($listedAt)) {
                throw ValidationException::withMessages([
                    'occurred_at' => [
                        'The outcome time cannot be earlier than the listing cycle.',
                    ],
                ]);
            }

            $money = $outcomeType->hasRealizedMoney()
                ? $this->money->normalize(
                    $attributes['amount_minor'],
                    $attributes['currency_code'],
                    $attributes['reporting_currency_code'],
                    $occurredAt,
                )
                : $this->emptyMoney();
            $sequence = ($currentSale?->sequence ?? 0) + 1;

            if ($sequence > (int) config(
                'outcome_tracking.maximum_sale_records_per_entry',
            )) {
                throw ValidationException::withMessages([
                    'sale_portfolio_entry_id' => [
                        'The actual sale history limit has been reached.',
                    ],
                ]);
            }

            $evidenceSnapshot = [
                'organization_id' => $organization->getKey(),
                'owned_product_id' => $product->getKey(),
                'sale_portfolio_entry_id' => $entry->getKey(),
                'sale_portfolio_event' => [
                    'id' => $currentEvent->getKey(),
                    'sequence' => $currentEvent->sequence,
                    'event_type' => $currentEvent->event_type->value,
                    'status' => $currentEvent->next_status->value,
                    'marketplace_key' => $currentEvent->marketplace_key,
                    'external_listing_id' => (
                        $currentEvent->external_listing_id
                    ),
                    'advertised_price_minor' => (
                        $currentEvent->advertised_price_minor
                    ),
                    'advertised_currency_code' => (
                        $currentEvent->advertised_currency_code
                    ),
                    'occurred_at' => (
                        $currentEvent->occurred_at->toIso8601String()
                    ),
                ],
                'previous_sale_id' => $currentSale?->getKey(),
                'outcome_type' => $outcomeType->value,
                'source_money' => $outcomeType->hasRealizedMoney() ? [
                    'amount_minor' => $money['source_amount_minor'],
                    'currency_code' => $money['source_currency_code'],
                ] : null,
                'reporting_money' => $outcomeType->hasRealizedMoney() ? [
                    'amount_minor' => $money['reporting_amount_minor'],
                    'currency_code' => $money['reporting_currency_code'],
                ] : null,
                'conversion' => $outcomeType->hasRealizedMoney()
                    ? $this->conversionSnapshot($money)
                    : null,
                'listed_at' => $listedAt->toIso8601String(),
                'occurred_at' => $occurredAt->toIso8601String(),
                'evidence_kind' => $attributes['evidence_kind'],
                'evidence_reference' => (
                    $attributes['evidence_reference'] ?? null
                ),
                'reason_code' => $attributes['reason_code'] ?? null,
                'correction_reason' => (
                    $attributes['correction_reason'] ?? null
                ),
                'note' => $attributes['note'] ?? null,
                'recording_method' => 'manual_user_entry',
            ];
            $inputHash = $this->hash($evidenceSnapshot);

            try {
                $sale = ActualSale::query()->create([
                    'organization_id' => $organization->getKey(),
                    'owned_product_id' => $product->getKey(),
                    'sale_portfolio_entry_id' => $entry->getKey(),
                    'sale_portfolio_event_id' => $currentEvent->getKey(),
                    'previous_sale_id' => $currentSale?->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'sequence' => $sequence,
                    'outcome_type' => $outcomeType,
                    ...$money,
                    'listed_at' => $listedAt,
                    'sale_duration_seconds' => (int) (
                        $listedAt->diffInSeconds($occurredAt)
                    ),
                    'evidence_kind' => $attributes['evidence_kind'],
                    'evidence_reference' => (
                        $attributes['evidence_reference'] ?? null
                    ),
                    'reason_code' => $attributes['reason_code'] ?? null,
                    'correction_reason' => (
                        $attributes['correction_reason'] ?? null
                    ),
                    'note' => $attributes['note'] ?? null,
                    'idempotency_key' => $attributes['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'input_hash' => $inputHash,
                    'evidence_snapshot' => $evidenceSnapshot,
                    'occurred_at' => $occurredAt,
                    'recorded_at' => now(),
                ]);
            } catch (QueryException $exception) {
                $collision = ActualSale::query()
                    ->forOrganization($organization)
                    ->where(
                        'idempotency_key',
                        $attributes['idempotency_key'],
                    )
                    ->first();

                if ($collision === null) {
                    throw $exception;
                }

                return $this->idempotent($collision, $payloadHash);
            }

            $this->profits->recordIfComplete($product);

            return [
                'sale' => $sale->load([
                    'actor:id,name',
                    'portfolioEvent',
                ]),
                'created' => true,
            ];
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guardOutcome(
        OwnedProduct $product,
        SalePortfolioEntry $entry,
        ?ActualSale $currentSale,
        SalePortfolioEvent $currentEvent,
        ActualSaleOutcomeType $outcomeType,
        array $attributes,
    ): void {
        if (
            $currentSale?->outcome_type === ActualSaleOutcomeType::Sold
            && $outcomeType !== ActualSaleOutcomeType::Sold
        ) {
            throw ValidationException::withMessages([
                'outcome_type' => [
                    'A confirmed sold outcome can only be corrected by another sold evidence version.',
                ],
            ]);
        }

        if ($outcomeType === ActualSaleOutcomeType::Sold) {
            if (! in_array($currentEvent->next_status, [
                SalePortfolioStatus::Listed,
                SalePortfolioStatus::Reserved,
            ], true)) {
                throw ValidationException::withMessages([
                    'outcome_type' => [
                        'A sold outcome requires a currently listed or reserved portfolio entry.',
                    ],
                ]);
            }

            $otherSold = ActualSale::query()
                ->where('owned_product_id', $product->getKey())
                ->where('outcome_type', ActualSaleOutcomeType::Sold->value)
                ->where(
                    'sale_portfolio_entry_id',
                    '!=',
                    $entry->getKey(),
                )
                ->lockForUpdate()
                ->exists();

            if ($otherSold) {
                throw ValidationException::withMessages([
                    'outcome_type' => [
                        'This product already has a realized sale on another portfolio entry.',
                    ],
                ]);
            }

            foreach ([
                'amount_minor',
                'currency_code',
                'reporting_currency_code',
            ] as $field) {
                if ($attributes[$field] === null) {
                    throw ValidationException::withMessages([
                        $field => [
                            'A sold outcome requires exact realized money.',
                        ],
                    ]);
                }
            }

            return;
        }

        if (! in_array($currentEvent->next_status, [
            SalePortfolioStatus::Withdrawn,
            SalePortfolioStatus::Expired,
        ], true)) {
            throw ValidationException::withMessages([
                'outcome_type' => [
                    'A cancelled or no-sale outcome requires a withdrawn or expired portfolio entry.',
                ],
            ]);
        }

        if (
            $attributes['amount_minor'] !== null
            || $attributes['currency_code'] !== null
            || $attributes['reporting_currency_code'] !== null
        ) {
            throw ValidationException::withMessages([
                'amount_minor' => [
                    'Cancelled and no-sale outcomes cannot contain realized sale money.',
                ],
            ]);
        }
    }

    private function listedAt(
        SalePortfolioEntry $entry,
        SalePortfolioEvent $currentEvent,
    ): CarbonImmutable {
        $listingEvent = $entry->events
            ->filter(static fn (SalePortfolioEvent $event): bool => (
                $event->sequence <= $currentEvent->sequence
                && in_array($event->event_type, [
                    SalePortfolioEventType::Published,
                    SalePortfolioEventType::Relisted,
                ], true)
            ))
            ->sortByDesc('sequence')
            ->first();

        if ($listingEvent === null) {
            throw ValidationException::withMessages([
                'sale_portfolio_event_id' => [
                    'The outcome requires an attributable publication event.',
                ],
            ]);
        }

        return $listingEvent->occurred_at;
    }

    private function guardHead(
        ?string $expectedId,
        ?ActualSale $current,
        ?string $correctionReason,
    ): void {
        if ($expectedId !== $current?->getKey()) {
            throw new OutcomeTrackingConflictException(
                ApiErrorCode::ActualSaleStaleState,
                'expected_current_sale_id',
                'The actual sale evidence changed. Refresh before recording another outcome.',
            );
        }

        if ($current !== null && $correctionReason === null) {
            throw ValidationException::withMessages([
                'correction_reason' => [
                    'A correction reason is required for a new sale outcome version.',
                ],
            ]);
        }
    }

    /**
     * @return array{sale: ActualSale, created: false}
     */
    private function idempotent(
        ActualSale $sale,
        string $payloadHash,
    ): array {
        if (! hash_equals($sale->payload_hash, $payloadHash)) {
            throw new OutcomeTrackingConflictException(
                ApiErrorCode::OutcomeIdempotencyConflict,
                'idempotency_key',
                'The idempotency key was already used with a different outcome command.',
            );
        }

        return [
            'sale' => $sale->load([
                'actor:id,name',
                'portfolioEvent',
            ]),
            'created' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyMoney(): array
    {
        return [
            'source_amount_minor' => null,
            'source_currency_code' => null,
            'reporting_amount_minor' => null,
            'reporting_currency_code' => null,
            'exchange_rate_id' => null,
            'rate_direction' => null,
            'rate_value' => null,
            'rate_effective_at' => null,
            'rate_provider' => null,
            'rate_provider_reference' => null,
            'conversion_calculated_at' => null,
        ];
    }

    /** @param array<string, mixed> $money */
    private function conversionSnapshot(array $money): array
    {
        return [
            'exchange_rate_id' => $money['exchange_rate_id'],
            'direction' => $money['rate_direction'],
            'rate_value' => $money['rate_value'],
            'effective_at' => (
                $money['rate_effective_at']?->toIso8601String()
            ),
            'provider' => $money['rate_provider'],
            'provider_reference' => $money['rate_provider_reference'],
            'calculated_at' => (
                $money['conversion_calculated_at']->toIso8601String()
            ),
        ];
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function payloadHash(
        Organization $organization,
        User $actor,
        SalePortfolioEntry $entry,
        array $attributes,
    ): string {
        return $this->hash([
            'organization_id' => $organization->getKey(),
            'owned_product_id' => $entry->owned_product_id,
            'sale_portfolio_entry_id' => $entry->getKey(),
            'actor_user_id' => $actor->getKey(),
            'expected_current_sale_id' => (
                $attributes['expected_current_sale_id']
            ),
            'sale_portfolio_event_id' => (
                $attributes['sale_portfolio_event_id']
            ),
            'outcome_type' => $attributes['outcome_type'],
            'amount_minor' => $attributes['amount_minor'],
            'currency_code' => $attributes['currency_code'],
            'reporting_currency_code' => (
                $attributes['reporting_currency_code']
            ),
            'occurred_at' => CarbonImmutable::parse(
                $attributes['occurred_at'],
            )->utc()->toIso8601String(),
            'evidence_kind' => $attributes['evidence_kind'],
            'evidence_reference' => (
                $attributes['evidence_reference'] ?? null
            ),
            'reason_code' => $attributes['reason_code'] ?? null,
            'correction_reason' => (
                $attributes['correction_reason'] ?? null
            ),
            'note' => $attributes['note'] ?? null,
        ]);
    }
}
