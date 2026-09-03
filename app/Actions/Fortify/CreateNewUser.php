<?php

namespace App\Actions\Fortify;

use App\Actions\Organizations\CreatePersonalOrganization;
use App\Enums\Localization\SupportedLocale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private readonly CreatePersonalOrganization $createPersonalOrganization,
    ) {}

    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $input['name'] = trim($input['name'] ?? '');
        $input['email'] = Str::lower(trim($input['email'] ?? ''));

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique(User::class),
            ],
            'preferred_locale' => [
                'sometimes',
                'string',
                'max:35',
                Rule::enum(SupportedLocale::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'preferred_locale' => SupportedLocale::tryFrom(
                    $input['preferred_locale'] ?? '',
                ) ?? SupportedLocale::English,
                'password' => Hash::make($input['password']),
            ]);

            $this->createPersonalOrganization->createFor($user);

            return $user;
        });
    }
}
