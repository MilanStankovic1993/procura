<?php

use App\Models\User;

test('password confirmation screen redirects authenticated users to Angular', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertRedirect(config('app.frontend_url').'/confirm-password');
});

test('users can confirm their current password', function () {
    $user = User::factory()->create(['password' => 'SecurePass123!']);

    $response = $this->actingAs($user)->post(route('password.confirm.store'), [
        'password' => 'SecurePass123!',
    ]);

    $response->assertRedirect(config('fortify.redirects.password-confirmation'))
        ->assertSessionHasNoErrors();
    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('password confirmation rejects an invalid password', function () {
    $user = User::factory()->create(['password' => 'SecurePass123!']);

    $response = $this->actingAs($user)
        ->from(route('password.confirm'))
        ->post(route('password.confirm.store'), ['password' => 'IncorrectPass123!']);

    $response->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('password');
});
