<?php

namespace App\Http\Requests\Api\V1\BrokerRequests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptBrokerRequestOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_request_event_id' => ['required', 'ulid'],
            'expected_offer_event_id' => ['required', 'ulid'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
