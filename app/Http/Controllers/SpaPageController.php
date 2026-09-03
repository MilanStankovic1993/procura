<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SpaPageController extends Controller
{
    public function home(): RedirectResponse
    {
        return $this->redirectTo('/');
    }

    public function dashboard(): RedirectResponse
    {
        return $this->redirectTo('/app');
    }

    public function application(Request $request): RedirectResponse
    {
        $path = '/'.ltrim($request->path(), '/');
        $url = rtrim((string) config('app.frontend_url'), '/').$path;
        $query = $request->getQueryString();

        if ($query !== null && $query !== '') {
            $url .= '?'.$query;
        }

        return redirect()->away($url);
    }

    public function login(Request $request): RedirectResponse
    {
        $returnUrl = $request->string('returnUrl')->toString();

        return $this->redirectTo('/login', $this->isSafeReturnUrl($returnUrl)
            ? ['returnUrl' => $returnUrl]
            : []);
    }

    public function register(): RedirectResponse
    {
        return $this->redirectTo('/register');
    }

    public function forgotPassword(): RedirectResponse
    {
        return $this->redirectTo('/forgot-password');
    }

    public function resetPassword(Request $request, string $token): RedirectResponse
    {
        return $this->redirectTo('/reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function verificationNotice(): RedirectResponse
    {
        return $this->redirectTo('/verify-email');
    }

    public function confirmPassword(Request $request): RedirectResponse
    {
        $returnUrl = $request->string('returnUrl')->toString();

        return $this->redirectTo('/confirm-password', $this->isSafeReturnUrl($returnUrl)
            ? ['returnUrl' => $returnUrl]
            : []);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function redirectTo(string $path, array $query = []): RedirectResponse
    {
        $url = rtrim((string) config('app.frontend_url'), '/').$path;
        $query = array_filter($query, fn (string $value): bool => $value !== '');

        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return redirect()->away($url);
    }

    private function isSafeReturnUrl(string $returnUrl): bool
    {
        return str_starts_with($returnUrl, '/') && ! str_starts_with($returnUrl, '//');
    }
}
