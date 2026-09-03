<?php

namespace App\Http\Requests\Api\V1\Monitoring;

use App\Enums\Monitoring\NotificationEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordNotificationStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_type' => [
                'required',
                Rule::in([
                    NotificationEventType::Read->value,
                    NotificationEventType::Unread->value,
                    NotificationEventType::Archived->value,
                ]),
            ],
            'expected_current_log_id' => ['required', 'string', 'max:26'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
