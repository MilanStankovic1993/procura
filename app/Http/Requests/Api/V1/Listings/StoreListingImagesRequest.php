<?php

namespace App\Http\Requests\Api\V1\Listings;

use App\Enums\Listings\ListingImageKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreListingImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxFiles = (int) config('listings.uploads.max_files_per_request');
        $maxSize = (int) config('listings.uploads.max_size_kilobytes');
        $minWidth = (int) config('listings.uploads.min_width');
        $minHeight = (int) config('listings.uploads.min_height');
        $maxWidth = (int) config('listings.uploads.max_width');
        $maxHeight = (int) config('listings.uploads.max_height');

        return [
            'kind' => ['required', Rule::enum(ListingImageKind::class)],
            'images' => ['required', 'array', 'min:1', "max:{$maxFiles}"],
            'images.*' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp',
                'mimes:jpg,jpeg,png,webp',
                "max:{$maxSize}",
                sprintf(
                    'dimensions:min_width=%d,min_height=%d,max_width=%d,max_height=%d',
                    $minWidth,
                    $minHeight,
                    $maxWidth,
                    $maxHeight,
                ),
            ],
        ];
    }
}
