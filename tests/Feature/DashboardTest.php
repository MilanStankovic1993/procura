<?php

use App\Models\User;

test('the transitional home route redirects to the Angular application', function () {
    $this->get(route('home'))
        ->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/');
});

test('guests are redirected from the dashboard to login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('unverified users are redirected to the verification notice', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('verified users are redirected from the transitional dashboard route to Angular', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(config('app.frontend_url').'/app');
});

test('direct Angular routes preserve their path and query through the Laravel host', function (
    string $path,
) {
    $response = $this->get($path)->assertRedirect();
    $location = (string) $response->headers->get('Location');
    $frontend = parse_url((string) config('app.frontend_url'));
    $expected = parse_url("http://local.test{$path}");
    $actual = parse_url($location);
    parse_str($expected['query'] ?? '', $expectedQuery);
    parse_str($actual['query'] ?? '', $actualQuery);

    expect($actual['scheme'] ?? null)->toBe($frontend['scheme'] ?? null)
        ->and($actual['host'] ?? null)->toBe($frontend['host'] ?? null)
        ->and($actual['port'] ?? null)->toBe($frontend['port'] ?? null)
        ->and($actual['path'] ?? null)->toBe($expected['path'] ?? null)
        ->and($actualQuery)->toEqual($expectedQuery);
})->with([
    'application root' => ['/app'],
    'deep analysis route' => [
        '/app/buy/01listing/analysis/01analysis?source=bookmark',
    ],
    'owned-product intake route' => ['/app/sell/new?source=manual'],
    'saved-search creation route' => [
        '/app/saved-searches/new?source=manual',
    ],
    'notification inbox route' => ['/app/notifications?state=unread'],
    'privacy request route' => ['/app/privacy'],
    'workspace recovery' => ['/workspace-unavailable'],
    'invitation acceptance' => ['/invitations/accept?token=signed-token'],
    'Angular reset form' => [
        '/reset-password?token=reset-token&email=buyer%40example.com',
    ],
    'Angular verification screen' => ['/verify-email'],
    'Angular confirmation screen' => ['/confirm-password?returnUrl=%2Fapp'],
]);

test('the Angular route adapter never captures backend or mutating requests', function () {
    $this->getJson('/api/v1/route-that-does-not-exist')->assertNotFound();
    $this->post('/app/buy')->assertStatus(405);
});
