<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Api\ApiErrorCode;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Outcomes\ActualCostCategory;
use App\Enums\Validation\ApplicationValidationCode;
use App\Exceptions\OutcomeTrackingConflictException;
use App\Models\ActualCostSnapshot;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\User;
use App\OutcomeTracking\OutcomeMoneyNormalizer;
use App\Support\Validation\ApplicationValidation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class RecordActualCostSnapshot
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly OutcomeMoneyNormalizer $money,
        private readonly RecordRealizedProfit $profits,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{snapshot: ActualCostSnapshot, created: bool}
     *
     * @throws JsonException
     */
    public function record(
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
            $payloadHash = $this->payloadHash(
                $organization,
                $actor,
                $product,
                $attributes,
            );
            $idempotent = ActualCostSnapshot::query()
                ->forOrganization($organization)
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($idempotent !== null) {
                return $this->idempotent($idempotent, $payloadHash);
            }

            $current = ActualCostSnapshot::query()
                ->where('owned_product_id', $product->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $this->guardHead(
                $attributes['expected_current_cost_snapshot_id'],
                $current,
                $attributes['correction_reason'] ?? null,
            );
            $reportingCurrency = strtoupper(
                $attributes['reporting_currency_code'],
            );
            $items = $this->normalizedItems(
                $attributes['items'],
                $reportingCurrency,
            );
            $knownCount = count(array_filter(
                $items,
                static fn (array $item): bool => $item['is_known'],
            ));
            $knownTotal = (int) array_sum(array_map(
                static fn (array $item): int => (
                    $item['reporting_amount_minor'] ?? 0
                ),
                $items,
            ));
            $sequence = ($current?->sequence ?? 0) + 1;

            if ($sequence > (int) config(
                'outcome_tracking.maximum_cost_snapshots_per_product',
            )) {
                ApplicationValidation::fail(
                    'owned_product',
                    ApplicationValidationCode::ActualCostHistoryLimit,
                );
            }

            $inputSnapshot = [
                'organization_id' => $organization->getKey(),
                'owned_product_id' => $product->getKey(),
                'previous_snapshot_id' => $current?->getKey(),
                'reporting_currency_code' => $reportingCurrency,
                'correction_reason' => (
                    $attributes['correction_reason'] ?? null
                ),
                'note' => $attributes['note'] ?? null,
                'items' => array_map(
                    fn (array $item): array => (
                        $this->itemEvidenceSnapshot($item)
                    ),
                    $items,
                ),
                'recording_method' => 'manual_user_entry',
            ];
            $inputHash = $this->hash($inputSnapshot);

            try {
                $snapshot = ActualCostSnapshot::query()->create([
                    'organization_id' => $organization->getKey(),
                    'owned_product_id' => $product->getKey(),
                    'previous_snapshot_id' => $current?->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'sequence' => $sequence,
                    'reporting_currency_code' => $reportingCurrency,
                    'known_count' => $knownCount,
                    'unknown_count' => count($items) - $knownCount,
                    'known_reporting_total_minor' => $knownTotal,
                    'correction_reason' => (
                        $attributes['correction_reason'] ?? null
                    ),
                    'note' => $attributes['note'] ?? null,
                    'idempotency_key' => $attributes['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'input_hash' => $inputHash,
                    'input_snapshot' => $inputSnapshot,
                    'recorded_at' => now(),
                ]);
                $snapshot->items()->createMany(array_map(
                    function (array $item): array {
                        $evidence = $this->itemEvidenceSnapshot($item);

                        return [
                            ...$item,
                            'evidence_hash' => $this->hash($evidence),
                            'evidence_snapshot' => $evidence,
                        ];
                    },
                    $items,
                ));
            } catch (QueryException $exception) {
                $collision = ActualCostSnapshot::query()
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
                'snapshot' => $snapshot->load([
                    'actor:id,name',
                    'items',
                ]),
                'created' => true,
            ];
        }, attempts: 3);
    }

    /**
     * @param  list<array<string, mixed>>  $inputItems
     * @return list<array<string, mixed>>
     */
    private function normalizedItems(
        array $inputItems,
        string $reportingCurrency,
    ): array {
        $byCategory = collect($inputItems)->keyBy('category');
        $expected = collect(ActualCostCategory::cases())->pluck('value');

        if (
            $byCategory->count() !== $expected->count()
            || $expected->contains(
                static fn (string $category): bool => (
                    ! $byCategory->has($category)
                ),
            )
        ) {
            ApplicationValidation::fail(
                'items',
                ApplicationValidationCode::ActualCostCategorySetInvalid,
            );
        }

        return array_map(function (
            ActualCostCategory $category,
        ) use ($byCategory, $reportingCurrency): array {
            $item = $byCategory->get($category->value);

            if (! $item['is_known']) {
                return [
                    'position' => $category->position(),
                    'category' => $category->value,
                    'is_known' => false,
                    'source_amount_minor' => null,
                    'source_currency_code' => null,
                    'reporting_amount_minor' => null,
                    'reporting_currency_code' => $reportingCurrency,
                    'exchange_rate_id' => null,
                    'rate_direction' => null,
                    'rate_value' => null,
                    'rate_effective_at' => null,
                    'rate_provider' => null,
                    'rate_provider_reference' => null,
                    'conversion_calculated_at' => null,
                    'occurred_at' => null,
                    'evidence_kind' => null,
                    'evidence_reference' => null,
                    'note' => null,
                ];
            }

            foreach ([
                'amount_minor',
                'currency_code',
                'occurred_at',
                'evidence_kind',
            ] as $requiredField) {
                if (
                    ! array_key_exists($requiredField, $item)
                    || $item[$requiredField] === null
                ) {
                    ApplicationValidation::fail(
                        'items',
                        ApplicationValidationCode::ActualCostKnownEvidenceIncomplete,
                    );
                }
            }

            $occurredAt = CarbonImmutable::parse(
                $item['occurred_at'],
            )->utc();
            $money = $this->money->normalize(
                $item['amount_minor'],
                $item['currency_code'],
                $reportingCurrency,
                $occurredAt,
                'items',
            );

            return [
                'position' => $category->position(),
                'category' => $category->value,
                'is_known' => true,
                ...$money,
                'occurred_at' => $occurredAt,
                'evidence_kind' => $item['evidence_kind'],
                'evidence_reference' => (
                    $item['evidence_reference'] ?? null
                ),
                'note' => $item['note'] ?? null,
            ];
        }, ActualCostCategory::cases());
    }

    /** @param array<string, mixed> $item */
    private function itemEvidenceSnapshot(array $item): array
    {
        return [
            'position' => $item['position'],
            'category' => $item['category'],
            'is_known' => $item['is_known'],
            'source_money' => $item['is_known'] ? [
                'amount_minor' => $item['source_amount_minor'],
                'currency_code' => $item['source_currency_code'],
            ] : null,
            'reporting_money' => $item['is_known'] ? [
                'amount_minor' => $item['reporting_amount_minor'],
                'currency_code' => $item['reporting_currency_code'],
            ] : null,
            'conversion' => $item['is_known'] ? [
                'exchange_rate_id' => $item['exchange_rate_id'],
                'direction' => $item['rate_direction'],
                'rate_value' => $item['rate_value'],
                'effective_at' => (
                    $item['rate_effective_at']?->toIso8601String()
                ),
                'provider' => $item['rate_provider'],
                'provider_reference' => (
                    $item['rate_provider_reference']
                ),
                'calculated_at' => (
                    $item['conversion_calculated_at']->toIso8601String()
                ),
            ] : null,
            'occurred_at' => (
                $item['occurred_at']?->toIso8601String()
            ),
            'evidence_kind' => $item['evidence_kind'],
            'evidence_reference' => $item['evidence_reference'],
            'note' => $item['note'],
        ];
    }

    private function guardHead(
        ?string $expectedId,
        ?ActualCostSnapshot $current,
        ?string $correctionReason,
    ): void {
        if ($expectedId !== $current?->getKey()) {
            throw new OutcomeTrackingConflictException(
                ApiErrorCode::ActualCostStaleState,
                'expected_current_cost_snapshot_id',
                'The actual cost evidence changed. Refresh before recording a correction.',
            );
        }

        if ($current !== null && $correctionReason === null) {
            ApplicationValidation::fail(
                'correction_reason',
                ApplicationValidationCode::CorrectionReasonRequired,
            );
        }
    }

    /**
     * @return array{snapshot: ActualCostSnapshot, created: false}
     */
    private function idempotent(
        ActualCostSnapshot $snapshot,
        string $payloadHash,
    ): array {
        if (! hash_equals($snapshot->payload_hash, $payloadHash)) {
            throw $this->idempotencyConflict();
        }

        return [
            'snapshot' => $snapshot->load([
                'actor:id,name',
                'items',
            ]),
            'created' => false,
        ];
    }

    private function idempotencyConflict(): OutcomeTrackingConflictException
    {
        return new OutcomeTrackingConflictException(
            ApiErrorCode::OutcomeIdempotencyConflict,
            'idempotency_key',
            'The idempotency key was already used with a different outcome command.',
        );
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
        OwnedProduct $product,
        array $attributes,
    ): string {
        $items = collect($attributes['items'])
            ->sortBy('category')
            ->values()
            ->map(static function (array $item): array {
                if (! $item['is_known']) {
                    return [
                        'category' => $item['category'],
                        'is_known' => false,
                    ];
                }

                return [
                    'category' => $item['category'],
                    'is_known' => true,
                    'amount_minor' => $item['amount_minor'],
                    'currency_code' => $item['currency_code'],
                    'occurred_at' => CarbonImmutable::parse(
                        $item['occurred_at'],
                    )->utc()->toIso8601String(),
                    'evidence_kind' => $item['evidence_kind'],
                    'evidence_reference' => (
                        $item['evidence_reference'] ?? null
                    ),
                    'note' => $item['note'] ?? null,
                ];
            })
            ->all();

        return $this->hash([
            'organization_id' => $organization->getKey(),
            'owned_product_id' => $product->getKey(),
            'actor_user_id' => $actor->getKey(),
            'expected_current_cost_snapshot_id' => (
                $attributes['expected_current_cost_snapshot_id']
            ),
            'reporting_currency_code' => (
                $attributes['reporting_currency_code']
            ),
            'correction_reason' => (
                $attributes['correction_reason'] ?? null
            ),
            'note' => $attributes['note'] ?? null,
            'items' => $items,
        ]);
    }
}
