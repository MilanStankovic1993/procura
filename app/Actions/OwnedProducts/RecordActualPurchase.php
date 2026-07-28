<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Api\ApiErrorCode;
use App\Enums\Organizations\OrganizationPermission;
use App\Exceptions\OutcomeTrackingConflictException;
use App\Models\ActualPurchase;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\User;
use App\OutcomeTracking\OutcomeMoneyNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class RecordActualPurchase
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly OutcomeMoneyNormalizer $money,
        private readonly RecordRealizedProfit $profits,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{purchase: ActualPurchase, created: bool}
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
            $idempotent = ActualPurchase::query()
                ->forOrganization($organization)
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($idempotent !== null) {
                return $this->idempotent($idempotent, $payloadHash);
            }

            $current = ActualPurchase::query()
                ->where('owned_product_id', $product->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $this->guardHead(
                $attributes['expected_current_purchase_id'],
                $current,
                $attributes['correction_reason'] ?? null,
            );
            $occurredAt = CarbonImmutable::parse(
                $attributes['occurred_at'],
            )->utc();
            $money = $this->money->normalize(
                $attributes['amount_minor'],
                $attributes['currency_code'],
                $attributes['reporting_currency_code'],
                $occurredAt,
            );
            $sequence = ($current?->sequence ?? 0) + 1;

            if ($sequence > (int) config(
                'outcome_tracking.maximum_purchase_records_per_product',
            )) {
                throw ValidationException::withMessages([
                    'owned_product' => [
                        'The actual purchase history limit has been reached.',
                    ],
                ]);
            }

            $evidenceSnapshot = [
                'organization_id' => $organization->getKey(),
                'owned_product_id' => $product->getKey(),
                'previous_purchase_id' => $current?->getKey(),
                'source_money' => [
                    'amount_minor' => $money['source_amount_minor'],
                    'currency_code' => $money['source_currency_code'],
                ],
                'reporting_money' => [
                    'amount_minor' => $money['reporting_amount_minor'],
                    'currency_code' => $money['reporting_currency_code'],
                ],
                'conversion' => $this->conversionSnapshot($money),
                'evidence_kind' => $attributes['evidence_kind'],
                'evidence_reference' => (
                    $attributes['evidence_reference'] ?? null
                ),
                'correction_reason' => (
                    $attributes['correction_reason'] ?? null
                ),
                'note' => $attributes['note'] ?? null,
                'occurred_at' => $occurredAt->toIso8601String(),
                'recording_method' => 'manual_user_entry',
            ];
            $inputHash = $this->hash($evidenceSnapshot);

            try {
                $purchase = ActualPurchase::query()->create([
                    'organization_id' => $organization->getKey(),
                    'owned_product_id' => $product->getKey(),
                    'previous_purchase_id' => $current?->getKey(),
                    'actor_user_id' => $actor->getKey(),
                    'sequence' => $sequence,
                    ...$money,
                    'evidence_kind' => $attributes['evidence_kind'],
                    'evidence_reference' => (
                        $attributes['evidence_reference'] ?? null
                    ),
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
                $collision = ActualPurchase::query()
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
                'purchase' => $purchase->load('actor:id,name'),
                'created' => true,
            ];
        }, attempts: 3);
    }

    private function guardHead(
        ?string $expectedId,
        ?ActualPurchase $current,
        ?string $correctionReason,
    ): void {
        if ($expectedId !== $current?->getKey()) {
            throw new OutcomeTrackingConflictException(
                ApiErrorCode::ActualPurchaseStaleState,
                'expected_current_purchase_id',
                'The actual purchase evidence changed. Refresh before recording a correction.',
            );
        }

        if ($current !== null && $correctionReason === null) {
            throw ValidationException::withMessages([
                'correction_reason' => [
                    'A correction reason is required for a new purchase evidence version.',
                ],
            ]);
        }
    }

    /**
     * @return array{purchase: ActualPurchase, created: false}
     */
    private function idempotent(
        ActualPurchase $purchase,
        string $payloadHash,
    ): array {
        if (! hash_equals($purchase->payload_hash, $payloadHash)) {
            throw $this->idempotencyConflict();
        }

        return [
            'purchase' => $purchase->load('actor:id,name'),
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
        OwnedProduct $product,
        array $attributes,
    ): string {
        return $this->hash([
            'organization_id' => $organization->getKey(),
            'owned_product_id' => $product->getKey(),
            'actor_user_id' => $actor->getKey(),
            'expected_current_purchase_id' => (
                $attributes['expected_current_purchase_id']
            ),
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
            'correction_reason' => (
                $attributes['correction_reason'] ?? null
            ),
            'note' => $attributes['note'] ?? null,
        ]);
    }
}
