<?php

namespace App\Http\Requests\Api\V1\Organizations;

use App\Enums\Organizations\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'role' => [
                'required',
                'string',
                Rule::in(array_map(
                    static fn (OrganizationRole $role): string => $role->value,
                    OrganizationRole::invitable(),
                )),
            ],
        ];
    }
}
