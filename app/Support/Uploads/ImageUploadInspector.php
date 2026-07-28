<?php

namespace App\Support\Uploads;

use App\Enums\Validation\ApplicationValidationCode;
use App\Support\Validation\ApplicationValidation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ImageUploadInspector
{
    /**
     * @return array{
     *     checksum_sha256: string,
     *     width: int,
     *     height: int,
     *     mime_type: string,
     *     extension: string,
     *     client_filename: string
     * }
     */
    public function inspect(UploadedFile $file, string $field): array
    {
        $path = $file->getRealPath();
        $checksum = is_string($path) ? hash_file('sha256', $path) : false;
        $dimensions = is_string($path) ? getimagesize($path) : false;

        if (! is_string($checksum) || $dimensions === false) {
            ApplicationValidation::fail(
                $field,
                ApplicationValidationCode::UnreadableImage,
            );
        }

        $mimeType = $file->getMimeType() ?? '';
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => ApplicationValidation::fail(
                $field,
                ApplicationValidationCode::UnsupportedImageType,
            ),
        };

        return [
            'checksum_sha256' => $checksum,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'mime_type' => $mimeType,
            'extension' => $extension,
            'client_filename' => $this->safeClientFilename(
                $file->getClientOriginalName(),
                $extension,
            ),
        ];
    }

    private function safeClientFilename(string $originalName, string $extension): string
    {
        $basename = pathinfo(basename($originalName), PATHINFO_FILENAME);
        $safeName = Str::of(Str::ascii($basename))
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-._')
            ->limit(160, '')
            ->toString();

        return ($safeName !== '' ? $safeName : 'image').".{$extension}";
    }
}
