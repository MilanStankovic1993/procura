<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\OwnedProducts\OwnedProductAssessmentStatus;
use App\Enums\OwnedProducts\OwnedProductStatus;
use App\Enums\Sell\SellListingDraftStatus;
use App\Enums\Sell\SellPhotoReadinessStatus;
use App\Enums\Sell\SellPriceBandStatus;
use App\Enums\Sell\SellPriceStrategy;
use App\Models\Currency;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\OwnedProductImage;
use App\Models\SellListingDraft;
use App\Models\SellPriceBand;
use App\Models\User;
use App\OwnedProductAssessment\CurrentOwnedProductAssessmentResolver;
use App\OwnedProductAssessment\OwnedProductAssessmentEvidence;
use App\SellListingContent\Generators\DeterministicSellListingContentGenerator;
use App\SellListingContent\PhotoReadiness\DeterministicPhotoReadinessEvaluator;
use App\SellListingContent\SellListingVersionEvidence;
use App\SellPriceIntelligence\CurrentSellPriceBandResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class CreateSellListingDraft
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly CurrentOwnedProductAssessmentResolver $currentAssessment,
        private readonly CurrentSellPriceBandResolver $currentPriceBand,
        private readonly DeterministicPhotoReadinessEvaluator $photoReadiness,
        private readonly DeterministicSellListingContentGenerator $generator,
        private readonly SellListingVersionEvidence $versionEvidence,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{draft: SellListingDraft, created: bool}
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
            $ownedProduct = OwnedProduct::query()
                ->forOrganization($organization)
                ->lockForUpdate()
                ->findOrFail($ownedProductId);

            if ($ownedProduct->status !== OwnedProductStatus::Ready) {
                throw ValidationException::withMessages([
                    'owned_product' => [
                        'The owned product must be ready before generating listing content.',
                    ],
                ]);
            }

            $assessment = $this->currentAssessment->resolve(
                $ownedProduct,
                lockForUpdate: true,
            );

            if (
                $assessment === null
                || $assessment->status !== OwnedProductAssessmentStatus::Ready
                || $assessment->matcher_status !== ProductMatchStatus::Matched
            ) {
                throw ValidationException::withMessages([
                    'owned_product_assessment_id' => [
                        'A current ready matched assessment is required.',
                    ],
                ]);
            }

            if (
                $assessment->getKey()
                !== $attributes['owned_product_assessment_id']
            ) {
                throw ValidationException::withMessages([
                    'owned_product_assessment_id' => [
                        'The owned-product assessment changed. Reload before generating a draft.',
                    ],
                ]);
            }

            $priceBand = $this->currentPriceBand->resolve(
                $ownedProduct,
                $assessment,
                $attributes['sell_price_band_id'],
                lockForUpdate: true,
            );

            if ($priceBand === null) {
                throw ValidationException::withMessages([
                    'sell_price_band_id' => [
                        'A current complete Sell price band is required.',
                    ],
                ]);
            }

            if (
                $priceBand->target_country_code
                    !== $attributes['target_country_code']
                || $priceBand->target_currency_code
                    !== $attributes['target_currency_code']
            ) {
                throw ValidationException::withMessages([
                    'sell_price_band_id' => [
                        'The selected price band does not match the requested market and currency.',
                    ],
                ]);
            }

            $strategy = SellPriceStrategy::from(
                $attributes['price_strategy'],
            );
            [$bandLow, $bandHigh] = $this->bandRange(
                $priceBand,
                $strategy,
            );
            $targetPrice = (int) $attributes['target_asking_price_minor'];
            $outsideBand = $targetPrice < $bandLow || $targetPrice > $bandHigh;
            $overrideReason = $this->nullableTrimmed(
                $attributes['price_override_reason'] ?? null,
            );

            if ($outsideBand && $overrideReason === null) {
                throw ValidationException::withMessages([
                    'price_override_reason' => [
                        'A reason is required when the target price is outside the selected guidance band.',
                    ],
                ]);
            }

            $images = OwnedProductImage::query()
                ->where('owned_product_id', $ownedProduct->getKey())
                ->lockForUpdate()
                ->get();
            $imageHash = OwnedProductAssessmentEvidence::imageHash($images);

            if (! hash_equals($assessment->image_evidence_hash, $imageHash)) {
                throw ValidationException::withMessages([
                    'owned_product_assessment_id' => [
                        'Image evidence changed. Create a new assessment before generating a draft.',
                    ],
                ]);
            }

            $snapshot = $assessment->snapshot()->firstOrFail();
            $currency = Currency::query()
                ->whereKey($priceBand->target_currency_code)
                ->where('active', true)
                ->firstOrFail();
            $photoReadiness = $this->photoReadiness->evaluate(
                $assessment,
                $images,
            );
            $listingLanguage = $attributes['listing_language'];
            $content = $this->generator->generate(
                assessment: $assessment,
                snapshot: $snapshot,
                priceBand: $priceBand,
                strategy: $strategy,
                targetAskingPriceMinor: $targetPrice,
                currencyMinorUnit: $currency->minor_unit,
                listingLanguage: $listingLanguage,
                photoReadiness: $photoReadiness,
                priceOutsideBand: $outsideBand,
            );
            $generatorVersion = (string) config(
                'sell_listing_content.generator_version',
            );
            $photoEvaluatorVersion = (string) config(
                'sell_listing_content.photo_evaluator_version',
            );
            $templateVersion = (string) config(
                "sell_listing_content.template_versions.{$listingLanguage}",
            );
            $templateContentHash = $this->versionEvidence->templateHash(
                $listingLanguage,
            );
            $photoPolicy = $this->versionEvidence->photoPolicy();
            $photoPolicyHash = $this->versionEvidence->photoPolicyHash();
            $imageSnapshot = OwnedProductAssessmentEvidence::imageSnapshot(
                $images,
            );
            $sourceIdentifiers = [
                [
                    'kind' => 'owned_product_snapshot',
                    'id' => $snapshot->getKey(),
                    'hash' => $snapshot->content_hash,
                ],
                [
                    'kind' => 'owned_product_assessment',
                    'id' => $assessment->getKey(),
                    'hash' => $assessment->input_hash,
                ],
                [
                    'kind' => 'sell_comparable_selection',
                    'id' => $priceBand->sell_comparable_selection_id,
                    'hash' => $priceBand->selection->input_hash,
                ],
                [
                    'kind' => 'sell_price_band',
                    'id' => $priceBand->getKey(),
                    'hash' => $priceBand->input_hash,
                ],
                ...collect($imageSnapshot)
                    ->map(static fn (array $image): array => [
                        'kind' => 'owned_product_image',
                        'id' => $image['id'],
                        'hash' => $image['checksum_sha256'],
                    ])
                    ->all(),
            ];
            $inputSnapshot = [
                'owned_product_id' => $ownedProduct->getKey(),
                'owned_product_snapshot_id' => $snapshot->getKey(),
                'snapshot_content_hash' => $snapshot->content_hash,
                'owned_product_assessment_id' => $assessment->getKey(),
                'assessment_input_hash' => $assessment->input_hash,
                'image_evidence_hash' => $imageHash,
                'sell_price_band_id' => $priceBand->getKey(),
                'price_band_input_hash' => $priceBand->input_hash,
                'sell_comparable_selection_id' => (
                    $priceBand->sell_comparable_selection_id
                ),
                'target_country_code' => $priceBand->target_country_code,
                'target_currency_code' => $priceBand->target_currency_code,
                'price_strategy' => $strategy->value,
                'selected_band_low_minor' => $bandLow,
                'selected_band_high_minor' => $bandHigh,
                'target_asking_price_minor' => $targetPrice,
                'price_override_reason' => $overrideReason,
                'listing_language' => $listingLanguage,
                'template_version' => $templateVersion,
                'template_content_hash' => $templateContentHash,
                'generator_version' => $generatorVersion,
                'photo_evaluator_version' => $photoEvaluatorVersion,
                'photo_policy' => $photoPolicy,
                'photo_policy_hash' => $photoPolicyHash,
                'images' => $imageSnapshot,
            ];
            $inputHash = hash(
                'sha256',
                json_encode(
                    $inputSnapshot,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
            );
            $draftKey = hash('sha256', implode('|', [
                $organization->getKey(),
                $ownedProduct->getKey(),
                $inputHash,
            ]));
            $existing = SellListingDraft::query()
                ->where('draft_key', $draftKey)
                ->first();

            if ($existing !== null) {
                return [
                    'draft' => $this->loadDraft($existing),
                    'created' => false,
                ];
            }

            $requiresReview = $photoReadiness->status
                    !== SellPhotoReadinessStatus::Ready
                || $outsideBand
                || $priceBand->status === SellPriceBandStatus::LowConfidence;
            $runNumber = ((int) SellListingDraft::query()
                ->where('owned_product_id', $ownedProduct->getKey())
                ->max('run_number')) + 1;
            $draft = SellListingDraft::query()->create([
                'organization_id' => $organization->getKey(),
                'owned_product_id' => $ownedProduct->getKey(),
                'owned_product_assessment_id' => $assessment->getKey(),
                'sell_price_band_id' => $priceBand->getKey(),
                'generated_by_user_id' => $actor->getKey(),
                'run_number' => $runNumber,
                'status' => $requiresReview
                    ? SellListingDraftStatus::ReviewRequired
                    : SellListingDraftStatus::Ready,
                'photo_readiness_status' => $photoReadiness->status,
                'photo_readiness_basis_points' => (
                    $photoReadiness->basisPoints
                ),
                'listing_language' => $listingLanguage,
                'template_version' => $templateVersion,
                'generator_version' => $generatorVersion,
                'photo_evaluator_version' => $photoEvaluatorVersion,
                'assessment_input_hash' => $assessment->input_hash,
                'price_band_input_hash' => $priceBand->input_hash,
                'image_evidence_hash' => $imageHash,
                'input_hash' => $inputHash,
                'draft_key' => $draftKey,
                'generated_at' => now(),
                'target_country_code' => $priceBand->target_country_code,
                'target_currency_code' => $priceBand->target_currency_code,
                'price_strategy' => $strategy,
                'target_asking_price_minor' => $targetPrice,
                'selected_band_low_minor' => $bandLow,
                'selected_band_high_minor' => $bandHigh,
                'price_override_reason' => $overrideReason,
                'title' => $content->title,
                'description' => $content->description,
                'completeness_basis_points' => (
                    $content->completenessBasisPoints
                ),
                'reason_codes' => $content->reasonCodes,
                'unknown_facts' => $content->unknownFacts,
                'warnings' => $content->warnings,
                'verification_actions' => $content->verificationActions,
                'source_fact_identifiers' => $sourceIdentifiers,
                'input_snapshot' => $inputSnapshot,
            ]);
            $draft->facts()->createMany($content->facts);
            $draft->photoChecklist()->createMany($photoReadiness->items);

            return [
                'draft' => $this->loadDraft($draft),
                'created' => true,
            ];
        }, attempts: 3);
    }

    /** @return array{0: int, 1: int} */
    private function bandRange(
        SellPriceBand $priceBand,
        SellPriceStrategy $strategy,
    ): array {
        return match ($strategy) {
            SellPriceStrategy::QuickSale => [
                $priceBand->quick_sale_low_minor,
                $priceBand->quick_sale_high_minor,
            ],
            SellPriceStrategy::Recommended => [
                $priceBand->recommended_low_minor,
                $priceBand->recommended_high_minor,
            ],
            SellPriceStrategy::Ambitious => [
                $priceBand->ambitious_low_minor,
                $priceBand->ambitious_high_minor,
            ],
        };
    }

    private function loadDraft(SellListingDraft $draft): SellListingDraft
    {
        return $draft->load([
            'facts',
            'photoChecklist',
            'generatedBy',
            'priceBand.selection',
        ]);
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
