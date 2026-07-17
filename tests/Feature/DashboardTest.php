<?php

use App\Models\User;

test('guests are redirected from the dashboard to login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('unverified users are redirected to the verification notice', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('verified users can view the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome, '.$user->name)
        ->assertSee('Verified');
});
