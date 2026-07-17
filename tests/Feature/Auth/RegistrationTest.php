<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('registration screen can be rendered', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Create your account');
});

test('new users can register and receive a verification email', function () {
    Notification::fake();

    $response = $this->post(route('register.store'), [
        'name' => '  Milan Stankovic  ',
        'email' => 'MILAN@EXAMPLE.COM',
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ]);

    $user = User::query()->sole();

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Milan Stankovic')
        ->and($user->email)->toBe('milan@example.com')
        ->and($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('registration rejects weak passwords and duplicate emails', function () {
    User::factory()->create(['email' => 'buyer@example.com']);

    $response = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Another Buyer',
        'email' => 'BUYER@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('register'))
        ->assertSessionHasErrors(['email', 'password']);
    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});
