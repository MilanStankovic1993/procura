<?php

use App\Actions\Organizations\CreatePersonalOrganization;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

test('users can register through the versioned API and receive an Angular verification link', function () {
    Notification::fake();

    $this->postJson(route('api.v1.auth.register'), [
        'name' => '  New Procura User  ',
        'email' => 'NEW.USER@EXAMPLE.COM',
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertCreated();

    $user = User::query()->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('New Procura User')
        ->and($user->email)->toBe('new.user@example.com')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->personalOrganization)->not->toBeNull();

    Notification::assertSentTo(
        $user,
        VerifyEmail::class,
        function (VerifyEmail $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_starts_with($url, config('app.frontend_url').'/verify-email?')
                && str_starts_with(
                    (string) ($query['verification'] ?? ''),
                    '/api/v1/auth/email/verify/'.$user->getKey().'/',
                );
        },
    );
});

test('registration preserves a supported interface locale independently of market context', function () {
    Notification::fake();

    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Localized User',
        'email' => 'localized@example.com',
        'preferred_locale' => 'fr',
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertCreated();

    expect(User::query()->where('email', 'localized@example.com')->firstOrFail())
        ->preferred_locale->value
        ->toBe('fr');
});

test('unverified sessions can inspect their identity but cannot access tenant operations', function () {
    $user = User::factory()->unverified()->create();
    app(CreatePersonalOrganization::class)->createFor($user);

    $this->actingAs($user)
        ->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.email_verified_at', null);

    $this->actingAs($user)
        ->getJson(route('api.v1.organizations.index'))
        ->assertForbidden();
});

test('authenticated users can verify their email through a relative signed API route', function () {
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();
    app(CreatePersonalOrganization::class)->createFor($user);

    $verificationPath = URL::temporarySignedRoute(
        'api.v1.auth.email.verify',
        now()->addMinutes(60),
        [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ],
        absolute: false,
    );

    $this->actingAs($user)
        ->getJson($verificationPath)
        ->assertNoContent();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);

    $this->actingAs($user)
        ->getJson(route('api.v1.organizations.index'))
        ->assertOk();
});

test('verification rejects tampered signed paths and can resend a fresh notification', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    app(CreatePersonalOrganization::class)->createFor($user);

    $verificationPath = URL::temporarySignedRoute(
        'api.v1.auth.email.verify',
        now()->addMinutes(60),
        [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ],
        absolute: false,
    );

    $this->actingAs($user)
        ->getJson($verificationPath.'&signature=tampered')
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson(route('api.v1.auth.email.verification.send'))
        ->assertAccepted();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('users can request and complete a password reset through the versioned API', function () {
    Notification::fake();
    $user = User::factory()->create();
    $token = null;

    $this->postJson(route('api.v1.auth.password.email'), [
        'email' => strtoupper($user->email),
    ])
        ->assertOk()
        ->assertJsonStructure(['message']);

    Notification::assertSentTo(
        $user,
        ResetPassword::class,
        function (ResetPassword $notification) use ($user, &$token): bool {
            $token = $notification->token;
            $url = $notification->toMail($user)->actionUrl;
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_starts_with($url, config('app.frontend_url').'/reset-password?')
                && ($query['token'] ?? null) === $token
                && ($query['email'] ?? null) === $user->email;
        },
    );

    $this->postJson(route('api.v1.auth.password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'UpdatedSecurePass456!',
        'password_confirmation' => 'UpdatedSecurePass456!',
    ])
        ->assertOk()
        ->assertJsonStructure(['message']);

    expect(Hash::check('UpdatedSecurePass456!', $user->fresh()->password))->toBeTrue();
    $this->assertGuest('web');
});

test('password reset requests do not disclose whether an account exists', function () {
    $knownUser = User::factory()->create();
    $message = 'If an account exists for that email address, a password reset link has been sent.';

    $this->postJson(route('api.v1.auth.password.email'), [
        'email' => $knownUser->email,
    ])
        ->assertOk()
        ->assertExactJson(['message' => $message]);

    $this->postJson(route('api.v1.auth.password.email'), [
        'email' => 'unknown@example.com',
    ])
        ->assertOk()
        ->assertExactJson(['message' => $message]);
});

test('password reset rejects invalid tokens and weak passwords', function () {
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'AnotherSecurePass456!',
        'password_confirmation' => 'AnotherSecurePass456!',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $validToken = Password::broker()->createToken($user);

    $this->postJson(route('api.v1.auth.password.update'), [
        'token' => $validToken,
        'email' => $user->email,
        'password' => 'weak',
        'password_confirmation' => 'weak',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    expect(Hash::check('weak', $user->fresh()->password))->toBeFalse();
});

test('authenticated users can inspect and establish password confirmation state', function () {
    $user = User::factory()->create(['password' => 'SecurePass123!']);

    $this->withHeader('Referer', config('app.frontend_url'))
        ->actingAs($user)
        ->getJson(route('api.v1.auth.password.confirmation'))
        ->assertOk()
        ->assertJsonPath('confirmed', false);

    $this->actingAs($user)
        ->postJson(route('api.v1.auth.password.confirm'), [
            'password' => 'IncorrectPass123!',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->actingAs($user)
        ->postJson(route('api.v1.auth.password.confirm'), [
            'password' => 'SecurePass123!',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->getJson(route('api.v1.auth.password.confirmation'))
        ->assertOk()
        ->assertJsonPath('confirmed', true);
});
