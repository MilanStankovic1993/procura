<?php

namespace App\Http\Requests\Api\V1\Analyses;

use App\Enums\Analyses\AnalysisStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'listing_id' => ['sometimes', 'string', 'size:26'],
            'status' => ['sometimes', Rule::enum(AnalysisStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
