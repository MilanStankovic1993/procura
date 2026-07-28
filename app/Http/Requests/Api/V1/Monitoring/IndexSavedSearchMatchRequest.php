<?php

namespace App\Http\Requests\Api\V1\Monitoring;

use App\Enums\Monitoring\SavedSearchMatchStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSavedSearchMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(SavedSearchMatchStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ];
    }
}
