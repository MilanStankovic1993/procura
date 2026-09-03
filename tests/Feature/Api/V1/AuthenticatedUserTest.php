<?php

use App\Actions\Organizations\CreatePersonalOrganization;
use App\Enums\Localization\SupportedLocale;
use App\Enums\Organizations\OrganizationRole;
use App\Models\User;

test('guests cannot read the current API user', function () {
    $this->getJson(route('api.v1.me'))
        ->assertUnauthorized();
});

test('authenticated users can read their API profile', function () {
    $user = User::factory()->create();
    $organization = app(CreatePersonalOrganization::class)->createFor($user);

    $this->actingAs($user)
        ->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', $user->name)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.preferred_locale', SupportedLocale::English->value)
        ->assertJsonPath('data.email_verified_at', $user->email_verified_at?->toIso8601String())
        ->assertJsonPath('data.current_organization.id', $organization->getKey())
        ->assertJsonPath('data.current_organization.role', OrganizationRole::Owner->value)
        ->assertJsonPath('data.current_organization.is_active', true)
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('data.current_organization.personal_user_id');
});

test('authenticated users can persist only a supported personal interface locale', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson(route('api.v1.me.preferences.update'), [
            'preferred_locale' => SupportedLocale::SerbianLatin->value,
        ])
        ->assertOk()
        ->assertJsonPath(
            'data.preferred_locale',
            SupportedLocale::SerbianLatin->value,
        );

    expect($user->refresh()->preferred_locale)
        ->toBe(SupportedLocale::SerbianLatin)
        ->and($user->preferredLocale())
        ->toBe(SupportedLocale::SerbianLatin->value);

    $this->patchJson(route('api.v1.me.preferences.update'), [
        'preferred_locale' => 'it',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('preferred_locale');
});

test('guests cannot mutate interface locale preferences', function () {
    $this->patchJson(route('api.v1.me.preferences.update'), [
        'preferred_locale' => SupportedLocale::German->value,
    ])->assertUnauthorized();
});
