<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => false,
    'home' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/').'/app',
    'prefix' => '',
    'domain' => null,
    'lowercase_usernames' => true,

    'redirects' => [
        'register' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/').'/verify-email',
        'email-verification' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/').'/app',
        'password-reset' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/').'/login',
        'password-confirmation' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/').'/app',
    ],

    'limiters' => [
        'login' => 'login',
        'verification' => '6,1',
    ],

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
    ],
];
