<?php

namespace App\Http\Requests\Api\V1\BrokerRequests;

use App\BrokerRequests\BrokerRequestInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreBrokerRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...BrokerRequestInput::rules(),
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
