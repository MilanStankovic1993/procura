<?php

namespace App\OwnedProductAssessment;

use App\Models\OwnedProductImage;
use Illuminate\Support\Collection;
use JsonException;

final class OwnedProductAssessmentEvidence
{
    /**
     * @param  Collection<int, OwnedProductImage>  $images
     * @return list<array<string, int|string>>
     */
    public static function imageSnapshot(Collection $images): array
    {
        return $images
            ->sortBy([
                ['kind', 'asc'],
                ['position', 'asc'],
                ['id', 'asc'],
            ])
            ->map(static fn (OwnedProductImage $image): array => [
                'id' => (string) $image->getKey(),
                'kind' => $image->kind->value,
                'position' => $image->position,
                'checksum_sha256' => $image->checksum_sha256,
                'size_bytes' => $image->size_bytes,
                'width' => $image->width,
                'height' => $image->height,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, OwnedProductImage>  $images
     *
     * @throws JsonException
     */
    public static function imageHash(Collection $images): string
    {
        return hash(
            'sha256',
            json_encode(
                self::imageSnapshot($images),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
