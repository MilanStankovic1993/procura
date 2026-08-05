<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Organizations\OrganizationPermission;
use App\Enums\OwnedProducts\OwnedProductStatus;
use App\Enums\Validation\ApplicationValidationCode;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\OwnedProductAssessment;
use App\Models\OwnedProductImage;
use App\Models\OwnedProductSnapshot;
use App\Models\User;
use App\OwnedProductAssessment\Contracts\OwnedProductAssessor;
use App\OwnedProductAssessment\Data\OwnedProductAssessmentInputData;
use App\OwnedProductAssessment\OwnedProductAssessmentEvidence;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Support\Facades\DB;
use JsonException;

class AssessOwnedProduct
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly OwnedProductAssessor $assessor,
    ) {}

    /**
     * @throws JsonException
     */
    public function assess(
        Organization $organization,
        User $actor,
        string $ownedProductId,
        string $expectedSnapshotId,
    ): OwnedProductAssessment {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $ownedProductId,
            $expectedSnapshotId,
        ): OwnedProductAssessment {
            $this->authorizer->authorize(
                $organization,
                $actor,
                OrganizationPermission::ManageOwnedProducts,
                lockForUpdate: true,
            );
            $ownedProduct = OwnedProduct::query()
                ->forOrganization($organization)
                ->lockForUpdate()
                ->findOrFail($ownedProductId);

            if ($ownedProduct->status !== OwnedProductStatus::Ready) {
                ApplicationValidation::fail(
                    'owned_product',
                    ApplicationValidationCode::OwnedProductNotReady,
                );
            }

            $snapshot = OwnedProductSnapshot::query()
                ->where('owned_product_id', $ownedProduct->getKey())
                ->with('category')
                ->lockForUpdate()
                ->orderByDesc('sequence')
                ->firstOrFail();

            if ($snapshot->getKey() !== $expectedSnapshotId) {
                ApplicationValidation::fail(
                    'owned_product_snapshot_id',
                    ApplicationValidationCode::OwnedProductAssessmentStale,
                );
            }

            $images = OwnedProductImage::query()
                ->where('owned_product_id', $ownedProduct->getKey())
                ->lockForUpdate()
                ->get();
            $imageSnapshot = OwnedProductAssessmentEvidence::imageSnapshot($images);
            $imageHash = OwnedProductAssessmentEvidence::imageHash($images);
            $inputHash = hash(
                'sha256',
                json_encode(
                    [
                        'snapshot_id' => $snapshot->getKey(),
                        'snapshot_content_hash' => $snapshot->content_hash,
                        'image_evidence_hash' => $imageHash,
                        'evaluator_version' => config(
                            'owned_product_assessment.evaluator_version',
                        ),
                        'matcher_version' => config(
                            'product_matching.matcher_version',
                        ),
                    ],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
            );
            $input = new OwnedProductAssessmentInputData(
                snapshotId: $snapshot->getKey(),
                snapshotContentHash: $snapshot->content_hash,
                imageEvidenceHash: $imageHash,
                inputHash: $inputHash,
                categoryName: $snapshot->category?->name,
                brandName: $snapshot->brand_name,
                modelName: $snapshot->model_name,
                condition: $snapshot->condition,
                accessories: $snapshot->accessories,
                defects: $snapshot->defects,
                targetCountryCodes: $snapshot->target_country_codes,
                images: $imageSnapshot,
            );
            $result = $this->assessor->assess($input);
            $assessmentKey = hash('sha256', implode('|', [
                $ownedProduct->getKey(),
                $snapshot->getKey(),
                $imageHash,
                $result->evaluatorVersion,
                $result->match->matcherVersion,
            ]));
            $existing = OwnedProductAssessment::query()
                ->where('assessment_key', $assessmentKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $runNumber = ((int) OwnedProductAssessment::query()
                ->where('owned_product_id', $ownedProduct->getKey())
                ->max('run_number')) + 1;

            return OwnedProductAssessment::query()->create([
                'organization_id' => $organization->getKey(),
                'owned_product_id' => $ownedProduct->getKey(),
                'owned_product_snapshot_id' => $snapshot->getKey(),
                'assessed_by_user_id' => $actor->getKey(),
                'run_number' => $runNumber,
                'status' => $result->status,
                'matcher_status' => $result->match->status,
                'review_status' => $result->match->reviewStatus,
                'method' => $result->match->method,
                'matcher_version' => $result->match->matcherVersion,
                'evaluator_version' => $result->evaluatorVersion,
                'snapshot_content_hash' => $snapshot->content_hash,
                'image_evidence_hash' => $imageHash,
                'input_hash' => $inputHash,
                'assessment_key' => $assessmentKey,
                'confidence_basis_points' => $result->confidenceBasisPoints,
                'completeness_basis_points' => $result->completenessBasisPoints,
                'product_category_id' => $result->productCategoryId,
                'product_model_id' => $result->match->productModelId,
                'product_variant_id' => $result->match->productVariantId,
                'identified_brand_name' => $result->identifiedBrandName,
                'identified_model_name' => $result->identifiedModelName,
                'identified_variant_name' => $result->identifiedVariantName,
                'condition' => $snapshot->condition,
                'included_accessories' => $result->includedAccessories,
                'missing_accessories' => $result->missingAccessories,
                'defects' => $result->defects,
                'candidate_snapshot' => $result->match->candidates,
                'reason_codes' => $result->reasonCodes,
                'unknown_facts' => $result->unknownFacts,
                'verification_actions' => $result->verificationActions,
                'input_snapshot' => $input->toArray(),
                'assessed_at' => now(),
            ]);
        }, attempts: 3);
    }
}
