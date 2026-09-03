<?php

namespace App\OwnedProductAssessment;

use App\Models\OwnedProduct;
use App\Models\OwnedProductAssessment;
use App\Models\OwnedProductImage;
use App\Models\OwnedProductSnapshot;
use JsonException;

final class CurrentOwnedProductAssessmentResolver
{
    public function matches(
        OwnedProductAssessment $assessment,
        OwnedProductSnapshot $snapshot,
        string $imageEvidenceHash,
    ): bool {
        return
            $assessment->owned_product_snapshot_id === $snapshot->getKey()
            && hash_equals(
                $assessment->snapshot_content_hash,
                $snapshot->content_hash,
            )
            && hash_equals(
                $assessment->image_evidence_hash,
                $imageEvidenceHash,
            )
            && hash_equals(
                $assessment->evaluator_version,
                (string) config('owned_product_assessment.evaluator_version'),
            )
            && hash_equals(
                $assessment->matcher_version,
                (string) config('product_matching.matcher_version'),
            );
    }

    /**
     * @throws JsonException
     */
    public function resolve(
        OwnedProduct $ownedProduct,
        bool $lockForUpdate = false,
    ): ?OwnedProductAssessment {
        $snapshotQuery = OwnedProductSnapshot::query()
            ->where('owned_product_id', $ownedProduct->getKey())
            ->orderByDesc('sequence');
        $imageQuery = OwnedProductImage::query()
            ->where('owned_product_id', $ownedProduct->getKey());

        if ($lockForUpdate) {
            $snapshotQuery->lockForUpdate();
            $imageQuery->lockForUpdate();
        }

        $snapshot = $snapshotQuery->first();

        if ($snapshot === null) {
            return null;
        }

        $imageEvidenceHash = OwnedProductAssessmentEvidence::imageHash(
            $imageQuery->get(),
        );
        $assessmentQuery = OwnedProductAssessment::query()
            ->where('organization_id', $ownedProduct->organization_id)
            ->where('owned_product_id', $ownedProduct->getKey())
            ->where('owned_product_snapshot_id', $snapshot->getKey())
            ->where('snapshot_content_hash', $snapshot->content_hash)
            ->where('image_evidence_hash', $imageEvidenceHash)
            ->where(
                'evaluator_version',
                (string) config('owned_product_assessment.evaluator_version'),
            )
            ->where(
                'matcher_version',
                (string) config('product_matching.matcher_version'),
            )
            ->orderByDesc('run_number');

        if ($lockForUpdate) {
            $assessmentQuery->lockForUpdate();
        }

        return $assessmentQuery->first();
    }
}
