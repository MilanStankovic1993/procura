<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => $this->frontendUrl(
            '/reset-password',
            [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ],
        ));

        VerifyEmail::createUrlUsing(function (User $user): string {
            $verificationPath = URL::temporarySignedRoute(
                'api.v1.auth.email.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $user->getKey(),
                    'hash' => sha1($user->getEmailForVerification()),
                ],
                absolute: false,
            );

            return $this->frontendUrl('/verify-email', ['verification' => $verificationPath]);
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::transliterate(Str::lower($request->string(Fortify::username())->toString()));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }

    /**
     * @param  array<string, string>  $query
     */
    private function frontendUrl(string $path, array $query = []): string
    {
        $url = rtrim((string) config('app.frontend_url'), '/').$path;

        return $query === []
            ? $url
            : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
