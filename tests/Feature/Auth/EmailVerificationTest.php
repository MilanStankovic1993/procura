<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

test('email verification screen redirects to the Angular application', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertRedirect(config('app.frontend_url').'/verify-email');
});

test('users can verify their email through a signed link', function () {
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    $response->assertRedirect(config('fortify.redirects.email-verification').'?verified=1');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

test('email verification rejects a validly signed link for the wrong email hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1('wrong@example.com')],
    );

    $this->actingAs($user)->get($verificationUrl)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
