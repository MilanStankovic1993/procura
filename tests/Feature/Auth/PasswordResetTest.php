<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('forgot password screen redirects to the Angular application', function () {
    $this->get(route('password.request'))
        ->assertRedirect(config('app.frontend_url').'/forgot-password');
});

test('users can request and complete a password reset', function () {
    Notification::fake();
    $user = User::factory()->create();

    $response = $this->post(route('password.email'), ['email' => $user->email]);

    $response->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertRedirect(config('app.frontend_url').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->email,
        ], '', '&', PHP_QUERY_RFC3986));

    $response = $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewSecurePass456!',
        'password_confirmation' => 'NewSecurePass456!',
    ]);

    $response->assertRedirect(config('fortify.redirects.password-reset'))
        ->assertSessionHasNoErrors();
    expect(Hash::check('NewSecurePass456!', $user->fresh()->password))->toBeTrue();
});
