<?php

use App\Models\User;

test('password confirmation screen can be rendered for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('Confirm it is you');
});

test('users can confirm their current password', function () {
    $user = User::factory()->create(['password' => 'SecurePass123!']);

    $response = $this->actingAs($user)->post(route('password.confirm.store'), [
        'password' => 'SecurePass123!',
    ]);

    $response->assertRedirect('/dashboard')
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
