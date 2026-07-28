<?php

use App\Models\User;

test('users can authenticate through the versioned SPA endpoint', function () {
    $user = User::factory()->create([
        'password' => 'procura-test-password',
    ]);

    $this->withHeader('Referer', 'http://localhost:4200')
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'procura-test-password',
            'remember' => true,
        ])
        ->assertOk()
        ->assertJsonPath('two_factor', false);

    $this->assertAuthenticatedAs($user);
});

test('invalid SPA credentials return validation errors', function () {
    $user = User::factory()->create([
        'password' => 'procura-test-password',
    ]);

    $this->withHeader('Referer', 'http://localhost:4200')
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertGuest('web');
});

test('authenticated users can end their SPA session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.auth.logout'))
        ->assertNoContent();

    $this->assertGuest('web');
});
