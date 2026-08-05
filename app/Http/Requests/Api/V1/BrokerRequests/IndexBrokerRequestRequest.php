<?php

namespace App\Http\Requests\Api\V1\BrokerRequests;

use App\Enums\BrokerRequests\BrokerRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexBrokerRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', Rule::enum(BrokerRequestStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ];
    }
}
