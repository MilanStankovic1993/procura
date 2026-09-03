<?php

use App\Models\User;

test('login screen redirects to the Angular application', function () {
    $this->get(route('login'))
        ->assertRedirect(config('app.frontend_url').'/login');
});

test('users can authenticate with their email and password', function () {
    $user = User::factory()->create([
        'email' => 'buyer@example.com',
        'password' => 'SecurePass123!',
    ]);

    $response = $this->post(route('login.store'), [
        'email' => 'BUYER@EXAMPLE.COM',
        'password' => 'SecurePass123!',
    ]);

    $response->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($user);
});

test('users cannot authenticate with an invalid password', function () {
    User::factory()->create([
        'email' => 'buyer@example.com',
        'password' => 'SecurePass123!',
    ]);

    $response = $this->from(route('login'))->post(route('login.store'), [
        'email' => 'buyer@example.com',
        'password' => 'IncorrectPass123!',
    ]);

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('authenticated users can log out', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect('/');
    $this->assertGuest();
});
