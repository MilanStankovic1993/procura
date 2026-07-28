<?php

namespace App\OwnedProductAssessment\Data;

use App\Enums\OwnedProducts\OwnedProductCondition;

final readonly class OwnedProductAssessmentInputData
{
    /**
     * @param  list<string>|null  $accessories
     * @param  list<string>|null  $defects
     * @param  list<string>  $targetCountryCodes
     * @param  list<array<string, int|string>>  $images
     */
    public function __construct(
        public string $snapshotId,
        public string $snapshotContentHash,
        public string $imageEvidenceHash,
        public string $inputHash,
        public ?string $categoryName,
        public ?string $brandName,
        public ?string $modelName,
        public OwnedProductCondition $condition,
        public ?array $accessories,
        public ?array $defects,
        public array $targetCountryCodes,
        public array $images,
    ) {}

    public function searchableTitle(): string
    {
        return trim(implode(' ', array_filter([
            $this->brandName,
            $this->modelName,
        ])));
    }

    public function imageCount(string $kind): int
    {
        return count(array_filter(
            $this->images,
            static fn (array $image): bool => $image['kind'] === $kind,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'snapshot_id' => $this->snapshotId,
            'snapshot_content_hash' => $this->snapshotContentHash,
            'image_evidence_hash' => $this->imageEvidenceHash,
            'category_name' => $this->categoryName,
            'brand_name' => $this->brandName,
            'model_name' => $this->modelName,
            'condition' => $this->condition->value,
            'accessories' => $this->accessories,
            'defects' => $this->defects,
            'target_country_codes' => $this->targetCountryCodes,
            'images' => $this->images,
        ];
    }
}
