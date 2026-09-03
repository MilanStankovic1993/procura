<?php

namespace App\Http\Requests\Api\V1\MarketplaceImports;

use App\Enums\Listings\MarketplaceImportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexMarketplaceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(MarketplaceImportStatus::class)],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
