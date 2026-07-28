<?php

namespace App\SellListingContent\PhotoReadiness;

use App\Enums\OwnedProducts\OwnedProductImageKind;
use App\Enums\Sell\SellPhotoCheckStatus;
use App\Enums\Sell\SellPhotoReadinessStatus;
use App\Models\OwnedProductAssessment;
use App\Models\OwnedProductImage;
use App\OwnedProductAssessment\OwnedProductAssessmentEvidence;
use App\SellListingContent\Data\SellPhotoReadinessData;
use Illuminate\Support\Collection;

final class DeterministicPhotoReadinessEvaluator
{
    /**
     * @param  Collection<int, OwnedProductImage>  $images
     */
    public function evaluate(
        OwnedProductAssessment $assessment,
        Collection $images,
    ): SellPhotoReadinessData {
        $productImages = $this->ofKind(
            $images,
            OwnedProductImageKind::Product,
        );
        $serialImages = $this->ofKind(
            $images,
            OwnedProductImageKind::SerialLabel,
        );
        $defectImages = $this->ofKind(
            $images,
            OwnedProductImageKind::Defect,
        );
        $proofImages = $this->ofKind(
            $images,
            OwnedProductImageKind::ProofOfPurchase,
        );
        $minimumProductImages = (int) config(
            'sell_listing_content.photo_readiness.minimum_product_images',
        );
        $minimumSerialImages = (int) config(
            'sell_listing_content.photo_readiness.minimum_serial_label_images',
        );
        $minimumShortEdge = (int) config(
            'sell_listing_content.photo_readiness.minimum_short_edge_pixels',
        );
        $minimumLongEdge = (int) config(
            'sell_listing_content.photo_readiness.minimum_long_edge_pixels',
        );
        $qualityImages = $productImages->filter(
            static fn (OwnedProductImage $image): bool => (
                min($image->width, $image->height) >= $minimumShortEdge
                && max($image->width, $image->height) >= $minimumLongEdge
            ),
        );
        $defectCount = count($assessment->defects ?? []);
        $accessoryCount = count($assessment->included_accessories ?? []);
        $items = [
            $this->countItem(
                position: 1,
                code: 'product_overview',
                kind: OwnedProductImageKind::Product,
                images: $productImages,
                minimum: 1,
                satisfiedReason: 'product_overview_available',
                missingReason: 'product_overview_missing',
                missingAction: 'provide_product_overview',
            ),
            $this->countItem(
                position: 2,
                code: 'multiple_product_angles',
                kind: OwnedProductImageKind::Product,
                images: $productImages,
                minimum: $minimumProductImages,
                satisfiedReason: 'multiple_product_angles_available',
                missingReason: 'multiple_product_angles_missing',
                missingAction: 'provide_multiple_product_angles',
            ),
            $this->countItem(
                position: 3,
                code: 'high_resolution_product_photos',
                kind: OwnedProductImageKind::Product,
                images: $qualityImages,
                minimum: $minimumProductImages,
                satisfiedReason: 'photo_resolution_sufficient',
                missingReason: 'photo_resolution_insufficient',
                missingAction: 'provide_high_resolution_product_photos',
                evidence: [
                    'minimum_short_edge_pixels' => $minimumShortEdge,
                    'minimum_long_edge_pixels' => $minimumLongEdge,
                    'all_product_image_ids' => $productImages
                        ->modelKeys(),
                ],
            ),
            $this->countItem(
                position: 4,
                code: 'serial_label',
                kind: OwnedProductImageKind::SerialLabel,
                images: $serialImages,
                minimum: $minimumSerialImages,
                satisfiedReason: 'serial_label_available',
                missingReason: 'serial_label_missing',
                missingAction: 'provide_serial_label_photo',
            ),
            $this->semanticItem(
                position: 5,
                code: 'defect_documentation',
                kind: OwnedProductImageKind::Defect,
                images: $defectImages,
                applicableCount: $defectCount,
                missingAction: 'provide_defect_photos',
                reviewAction: 'confirm_each_defect_visible',
                notApplicableReason: 'no_disclosed_defects',
                reviewReason: 'defect_photos_require_review',
                missingReason: 'defect_photos_missing',
            ),
            $this->semanticItem(
                position: 6,
                code: 'accessory_visibility',
                kind: OwnedProductImageKind::Product,
                images: $productImages,
                applicableCount: $accessoryCount,
                missingAction: 'provide_accessory_group_photo',
                reviewAction: 'confirm_accessories_visible',
                notApplicableReason: 'no_included_accessories',
                reviewReason: 'accessory_visibility_requires_review',
                missingReason: 'accessory_photo_missing',
            ),
            $this->proofItem(7, $proofImages),
        ];
        $requiredItems = collect($items)->where('required', true);
        $basisPoints = $requiredItems->isEmpty()
            ? 10000
            : (int) round(
                $requiredItems->sum(
                    static fn (array $item): int => match ($item['status']) {
                        SellPhotoCheckStatus::Satisfied->value,
                        SellPhotoCheckStatus::NotApplicable->value => 10000,
                        SellPhotoCheckStatus::ReviewRequired->value => 5000,
                        default => 0,
                    },
                ) / $requiredItems->count(),
            );
        $hasMissing = $requiredItems->contains(
            static fn (array $item): bool => (
                $item['status'] === SellPhotoCheckStatus::Missing->value
            ),
        );
        $hasReview = collect($items)->contains(
            static fn (array $item): bool => (
                $item['status'] === SellPhotoCheckStatus::ReviewRequired->value
            ),
        );
        $status = $hasMissing
            ? SellPhotoReadinessStatus::NeedsPhotos
            : ($hasReview
                ? SellPhotoReadinessStatus::ReviewRequired
                : SellPhotoReadinessStatus::Ready);
        $actions = collect($items)
            ->flatMap(static fn (array $item): array => (
                $item['verification_actions']
            ))
            ->unique()
            ->values()
            ->all();

        return new SellPhotoReadinessData(
            status: $status,
            basisPoints: $basisPoints,
            items: $items,
            reasonCodes: ['deterministic_photo_checklist'],
            warnings: match ($status) {
                SellPhotoReadinessStatus::NeedsPhotos => [
                    'photo_evidence_incomplete',
                ],
                SellPhotoReadinessStatus::ReviewRequired => [
                    'photo_semantic_review_required',
                ],
                SellPhotoReadinessStatus::Ready => [],
            },
            verificationActions: $actions,
        );
    }

    /**
     * @param  Collection<int, OwnedProductImage>  $images
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function countItem(
        int $position,
        string $code,
        OwnedProductImageKind $kind,
        Collection $images,
        int $minimum,
        string $satisfiedReason,
        string $missingReason,
        string $missingAction,
        array $evidence = [],
    ): array {
        $satisfied = $images->count() >= $minimum;

        return $this->item(
            position: $position,
            code: $code,
            status: $satisfied
                ? SellPhotoCheckStatus::Satisfied
                : SellPhotoCheckStatus::Missing,
            required: true,
            kind: $kind,
            minimum: $minimum,
            images: $images,
            reasonCodes: [$satisfied ? $satisfiedReason : $missingReason],
            actions: $satisfied ? [] : [$missingAction],
            evidence: $evidence,
        );
    }

    /**
     * @param  Collection<int, OwnedProductImage>  $images
     * @return array<string, mixed>
     */
    private function semanticItem(
        int $position,
        string $code,
        OwnedProductImageKind $kind,
        Collection $images,
        int $applicableCount,
        string $missingAction,
        string $reviewAction,
        string $notApplicableReason,
        string $reviewReason,
        string $missingReason,
    ): array {
        if ($applicableCount === 0) {
            return $this->item(
                position: $position,
                code: $code,
                status: SellPhotoCheckStatus::NotApplicable,
                required: false,
                kind: $kind,
                minimum: 0,
                images: $images,
                reasonCodes: [$notApplicableReason],
                actions: [],
                evidence: ['disclosed_fact_count' => 0],
            );
        }

        $minimum = $kind === OwnedProductImageKind::Defect
            ? $applicableCount
            : 1;
        $enough = $images->count() >= $minimum;

        return $this->item(
            position: $position,
            code: $code,
            status: $enough
                ? SellPhotoCheckStatus::ReviewRequired
                : SellPhotoCheckStatus::Missing,
            required: true,
            kind: $kind,
            minimum: $minimum,
            images: $images,
            reasonCodes: [$enough ? $reviewReason : $missingReason],
            actions: [$enough ? $reviewAction : $missingAction],
            evidence: ['disclosed_fact_count' => $applicableCount],
        );
    }

    /**
     * @param  Collection<int, OwnedProductImage>  $images
     * @return array<string, mixed>
     */
    private function proofItem(int $position, Collection $images): array
    {
        $present = $images->isNotEmpty();

        return $this->item(
            position: $position,
            code: 'private_proof_exclusion',
            status: $present
                ? SellPhotoCheckStatus::ReviewRequired
                : SellPhotoCheckStatus::NotApplicable,
            required: false,
            kind: OwnedProductImageKind::ProofOfPurchase,
            minimum: 0,
            images: $images,
            reasonCodes: [
                $present
                    ? 'proof_of_purchase_must_remain_private'
                    : 'no_private_proof_image',
            ],
            actions: $present ? ['keep_proof_of_purchase_private'] : [],
        );
    }

    /**
     * @param  Collection<int, OwnedProductImage>  $images
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $actions
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function item(
        int $position,
        string $code,
        SellPhotoCheckStatus $status,
        bool $required,
        OwnedProductImageKind $kind,
        int $minimum,
        Collection $images,
        array $reasonCodes,
        array $actions,
        array $evidence = [],
    ): array {
        return [
            'position' => $position,
            'check_code' => $code,
            'status' => $status->value,
            'required' => $required,
            'image_kind' => $kind->value,
            'minimum_count' => $minimum,
            'observed_count' => $images->count(),
            'matching_image_ids' => $images->modelKeys(),
            'reason_codes' => $reasonCodes,
            'verification_actions' => $actions,
            'evidence_snapshot' => [
                ...$evidence,
                'images' => OwnedProductAssessmentEvidence::imageSnapshot(
                    $images,
                ),
            ],
        ];
    }

    /**
     * @param  Collection<int, OwnedProductImage>  $images
     * @return Collection<int, OwnedProductImage>
     */
    private function ofKind(
        Collection $images,
        OwnedProductImageKind $kind,
    ): Collection {
        return $images
            ->filter(static fn (OwnedProductImage $image): bool => (
                $image->kind === $kind
            ))
            ->values();
    }
}
