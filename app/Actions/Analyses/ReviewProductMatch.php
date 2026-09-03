<?php

namespace App\Actions\Analyses;

use App\Actions\Administration\RecordPlatformAuditEvent;
use App\Catalog\CatalogTextNormalizer;
use App\Enums\Catalog\ProductMatchReviewDecision;
use App\Enums\Catalog\ProductMatchReviewStatus;
use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Localization\SupportedLocale;
use App\Exceptions\ProductMatchReviewConflictException;
use App\Jobs\Monitoring\MatchListingSnapshot;
use App\Models\Analysis;
use App\Models\ProductAlias;
use App\Models\ProductMatch;
use App\Models\ProductMatchReviewEvent;
use App\Models\ProductModel;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class ReviewProductMatch
{
    private const REVIEW_VERSION = 'operator-product-match-review:v1';

    public function __construct(
        private readonly ProductMatchReviewResultProjection $projection,
        private readonly RefreshComparableSelection $comparableSelection,
        private readonly RecordPlatformAuditEvent $audit,
    ) {}

    /**
     * @return array{
     *     event: ProductMatchReviewEvent,
     *     result_match: ProductMatch|null,
     *     alias: ProductAlias|null,
     *     created: bool
     * }
     */
    public function review(
        ProductMatch $productMatch,
        User $operator,
        ProductMatchReviewDecision $decision,
        string $expectedCurrentMatchId,
        string $idempotencyKey,
        string $reason,
        ?ProductModel $productModel = null,
        ?ProductVariant $productVariant = null,
        ?string $alias = null,
        ?string $aliasLocale = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $reason = trim($reason);
        $alias = $alias === null ? null : trim($alias);
        $alias = $alias === '' ? null : $alias;

        $this->validateInput(
            $decision,
            $expectedCurrentMatchId,
            $idempotencyKey,
            $reason,
            $productModel,
            $productVariant,
            $alias,
            $aliasLocale,
        );

        $payloadHash = $this->payloadHash(
            $productMatch,
            $decision,
            $reason,
            $productModel,
            $productVariant,
            $alias,
            $aliasLocale,
        );

        return DB::transaction(function () use (
            $productMatch,
            $operator,
            $decision,
            $expectedCurrentMatchId,
            $idempotencyKey,
            $reason,
            $productModel,
            $productVariant,
            $alias,
            $aliasLocale,
            $payloadHash,
            $ipAddress,
            $userAgent,
        ): array {
            $lockedOperator = User::query()
                ->lockForUpdate()
                ->findOrFail($operator->getKey());

            if (
                ! $lockedOperator->is_super_admin
                || ! $lockedOperator->hasVerifiedEmail()
                || $lockedOperator->privacy_erased_at !== null
            ) {
                throw new AuthorizationException;
            }

            $analysis = Analysis::query()
                ->lockForUpdate()
                ->findOrFail($productMatch->analysis_id);
            $lockedMatch = ProductMatch::query()
                ->lockForUpdate()
                ->findOrFail($productMatch->getKey());

            if (
                $lockedMatch->analysis_id !== $analysis->getKey()
                || $lockedMatch->organization_id !== $analysis->organization_id
            ) {
                throw new LogicException(
                    'The product match does not belong to the locked analysis.',
                );
            }
            $replay = ProductMatchReviewEvent::query()
                ->where('analysis_id', $analysis->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($replay !== null) {
                if (
                    $replay->product_match_id !== $lockedMatch->getKey()
                    || $replay->payload_hash !== $payloadHash
                ) {
                    throw new ProductMatchReviewConflictException(
                        'product_match_review_idempotency_conflict',
                        'The idempotency key was already used for another review payload.',
                    );
                }

                return [
                    'event' => $replay,
                    'result_match' => $replay->resultProductMatch,
                    'alias' => $replay->productAlias,
                    'created' => false,
                ];
            }

            $currentMatch = ProductMatch::query()
                ->where('analysis_id', $analysis->getKey())
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $expectedCurrentMatchId !== $lockedMatch->getKey()
                || ! $currentMatch->is($lockedMatch)
            ) {
                throw new ProductMatchReviewConflictException(
                    'product_match_review_head_changed',
                    'The current product match changed before the review was recorded.',
                );
            }

            if (! $this->isReviewable($lockedMatch)) {
                throw new ProductMatchReviewConflictException(
                    'product_match_review_already_resolved',
                    'The product match review is no longer pending.',
                );
            }

            [$lockedModel, $lockedVariant] = $this->lockSelection(
                $decision,
                $productModel,
                $productVariant,
            );
            $createdAlias = $this->createAlias(
                $decision,
                $analysis,
                $lockedModel,
                $lockedVariant,
                $alias,
                $aliasLocale,
            );
            $reviewedAt = now();
            $resultMatch = $decision === ProductMatchReviewDecision::Confirm
                ? $this->createConfirmedMatch(
                    $analysis,
                    $lockedMatch,
                    $lockedModel,
                    $lockedVariant,
                    $lockedOperator,
                    $idempotencyKey,
                    $payloadHash,
                    $reviewedAt,
                )
                : null;
            $reviewStatus = $decision === ProductMatchReviewDecision::Confirm
                ? ProductMatchReviewStatus::Confirmed
                : ProductMatchReviewStatus::Rejected;

            $lockedMatch->update([
                'review_status' => $reviewStatus,
                'reviewed_by_user_id' => $lockedOperator->getKey(),
                'reviewed_at' => $reviewedAt,
            ]);

            $event = ProductMatchReviewEvent::query()->create([
                'organization_id' => $analysis->organization_id,
                'analysis_id' => $analysis->getKey(),
                'product_match_id' => $lockedMatch->getKey(),
                'result_product_match_id' => $resultMatch?->getKey(),
                'actor_user_id' => $lockedOperator->getKey(),
                'decision' => $decision,
                'product_model_id' => $lockedModel?->getKey(),
                'product_variant_id' => $lockedVariant?->getKey(),
                'product_alias_id' => $createdAlias?->getKey(),
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'reviewed_at' => $reviewedAt,
            ]);

            $this->projection->apply(
                $analysis,
                $lockedMatch->fresh(),
                $decision,
                $resultMatch,
            );

            if ($resultMatch !== null) {
                $this->comparableSelection->refresh(
                    $analysis->fresh(),
                    $resultMatch,
                );
            }

            $this->audit->record(
                actor: $lockedOperator,
                action: 'product_match.reviewed',
                subject: $event,
                reason: $reason,
                organization: $analysis->organization,
                oldValues: [
                    'product_match_id' => $lockedMatch->getKey(),
                    'review_status' => ProductMatchReviewStatus::Pending->value,
                ],
                newValues: [
                    'decision' => $decision->value,
                    'review_status' => $reviewStatus->value,
                    'result_product_match_id' => $resultMatch?->getKey(),
                    'product_model_id' => $lockedModel?->getKey(),
                    'product_variant_id' => $lockedVariant?->getKey(),
                    'product_alias_id' => $createdAlias?->getKey(),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            DB::afterCommit(
                static fn () => MatchListingSnapshot::dispatch(
                    $analysis->listing_snapshot_id,
                ),
            );

            return [
                'event' => $event,
                'result_match' => $resultMatch,
                'alias' => $createdAlias,
                'created' => true,
            ];
        }, attempts: 3);
    }

    public function isReviewable(ProductMatch $productMatch): bool
    {
        return $productMatch->review_status === ProductMatchReviewStatus::Pending
            && in_array($productMatch->status, [
                ProductMatchStatus::ReviewRequired,
                ProductMatchStatus::Unmatched,
            ], true);
    }

    private function validateInput(
        ProductMatchReviewDecision $decision,
        string $expectedCurrentMatchId,
        string $idempotencyKey,
        string $reason,
        ?ProductModel $productModel,
        ?ProductVariant $productVariant,
        ?string $alias,
        ?string $aliasLocale,
    ): void {
        if ($expectedCurrentMatchId === '') {
            throw new InvalidArgumentException('The expected product match is required.');
        }

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('A valid idempotency UUID is required.');
        }

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException(
                'The review reason must contain between 10 and 1000 characters.',
            );
        }

        if (
            $decision === ProductMatchReviewDecision::Confirm
            && $productModel === null
        ) {
            throw new InvalidArgumentException(
                'A confirmed review requires a canonical product model.',
            );
        }

        if (
            $decision === ProductMatchReviewDecision::Reject
            && ($productModel !== null || $productVariant !== null || $alias !== null)
        ) {
            throw new InvalidArgumentException(
                'A rejected review cannot select a product or create an alias.',
            );
        }

        if ($alias !== null && (mb_strlen($alias) < 2 || mb_strlen($alias) > 180)) {
            throw new InvalidArgumentException(
                'A catalog alias must contain between 2 and 180 characters.',
            );
        }

        if (
            $aliasLocale !== null
            && ! in_array($aliasLocale, SupportedLocale::values(), true)
        ) {
            throw new InvalidArgumentException('The alias locale is not supported.');
        }
    }

    /** @return array{ProductModel|null, ProductVariant|null} */
    private function lockSelection(
        ProductMatchReviewDecision $decision,
        ?ProductModel $productModel,
        ?ProductVariant $productVariant,
    ): array {
        if ($decision === ProductMatchReviewDecision::Reject) {
            return [null, null];
        }

        $lockedModel = ProductModel::query()
            ->lockForUpdate()
            ->findOrFail($productModel?->getKey());

        if (! $lockedModel->active) {
            throw new LogicException('An inactive product model cannot be confirmed.');
        }

        $lockedVariant = $productVariant === null
            ? null
            : ProductVariant::query()
                ->lockForUpdate()
                ->findOrFail($productVariant->getKey());

        if (
            $lockedVariant !== null
            && (
                ! $lockedVariant->active
                || $lockedVariant->product_model_id !== $lockedModel->getKey()
            )
        ) {
            throw new LogicException(
                'The selected variant is not an active variant of the product model.',
            );
        }

        return [$lockedModel, $lockedVariant];
    }

    private function createAlias(
        ProductMatchReviewDecision $decision,
        Analysis $analysis,
        ?ProductModel $productModel,
        ?ProductVariant $productVariant,
        ?string $alias,
        ?string $aliasLocale,
    ): ?ProductAlias {
        if ($decision === ProductMatchReviewDecision::Reject || $alias === null) {
            return null;
        }

        $normalizedAlias = CatalogTextNormalizer::normalize($alias);

        if ($normalizedAlias === '') {
            throw new InvalidArgumentException(
                'A catalog alias must contain searchable characters.',
            );
        }

        $existing = ProductAlias::query()
            ->where('normalized_alias', $normalizedAlias)
            ->where('locale', $aliasLocale)
            ->where('country_code', $analysis->target_country_code)
            ->where('source', 'operator_review')
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if (
                $existing->product_model_id !== $productModel?->getKey()
                || $existing->product_variant_id !== $productVariant?->getKey()
            ) {
                throw new ProductMatchReviewConflictException(
                    'product_match_review_alias_conflict',
                    'The active operator alias already identifies another product.',
                );
            }

            return $existing;
        }

        return ProductAlias::query()->create([
            'product_model_id' => $productModel?->getKey(),
            'product_variant_id' => $productVariant?->getKey(),
            'alias' => $alias,
            'locale' => $aliasLocale,
            'country_code' => $analysis->target_country_code,
            'source' => 'operator_review',
            'active' => true,
        ]);
    }

    private function createConfirmedMatch(
        Analysis $analysis,
        ProductMatch $sourceMatch,
        ?ProductModel $productModel,
        ?ProductVariant $productVariant,
        User $operator,
        string $idempotencyKey,
        string $payloadHash,
        mixed $reviewedAt,
    ): ProductMatch {
        $runNumber = ((int) ProductMatch::query()
            ->where('analysis_id', $analysis->getKey())
            ->max('run_number')) + 1;

        return ProductMatch::query()->create([
            'organization_id' => $analysis->organization_id,
            'analysis_id' => $analysis->getKey(),
            'ai_analysis_id' => $sourceMatch->ai_analysis_id,
            'product_model_id' => $productModel?->getKey(),
            'product_variant_id' => $productVariant?->getKey(),
            'run_number' => $runNumber,
            'status' => ProductMatchStatus::Matched,
            'review_status' => ProductMatchReviewStatus::Confirmed,
            'method' => 'operator_review',
            'matcher_version' => self::REVIEW_VERSION,
            'input_hash' => $payloadHash,
            'match_key' => hash('sha256', implode('|', [
                $sourceMatch->getKey(),
                $idempotencyKey,
                self::REVIEW_VERSION,
            ])),
            'confidence_basis_points' => 10000,
            'candidate_snapshot' => $sourceMatch->candidate_snapshot,
            'reason_codes' => ['operator_confirmed_product_match'],
            'reviewed_by_user_id' => $operator->getKey(),
            'reviewed_at' => $reviewedAt,
        ]);
    }

    private function payloadHash(
        ProductMatch $productMatch,
        ProductMatchReviewDecision $decision,
        string $reason,
        ?ProductModel $productModel,
        ?ProductVariant $productVariant,
        ?string $alias,
        ?string $aliasLocale,
    ): string {
        return hash('sha256', json_encode([
            'version' => self::REVIEW_VERSION,
            'product_match_id' => (string) $productMatch->getKey(),
            'decision' => $decision->value,
            'product_model_id' => $productModel?->getKey(),
            'product_variant_id' => $productVariant?->getKey(),
            'alias' => $alias,
            'alias_locale' => $aliasLocale,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
