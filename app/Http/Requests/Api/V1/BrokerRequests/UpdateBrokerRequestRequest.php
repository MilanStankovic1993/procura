<?php

namespace App\Http\Requests\Api\V1\BrokerRequests;

use App\BrokerRequests\BrokerRequestInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrokerRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...BrokerRequestInput::rules(),
            'expected_current_event_id' => ['required', 'ulid'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
