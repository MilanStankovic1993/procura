<?php

namespace App\Http\Middleware;

use App\Support\Localization\RequestLocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetAuthenticatedUserLocale
{
    public function __construct(
        private readonly RequestLocaleResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $originalLocale = App::currentLocale();
        $locale = $this->resolver->resolve($request);
        $request->attributes->set(
            RequestLocaleResolver::REQUEST_ATTRIBUTE,
            $locale->value,
        );

        try {
            App::setLocale($locale->laravelLocale());
            $response = $next($request);
            $response->headers->set(
                'Content-Language',
                $locale->value,
            );

            return $response;
        } finally {
            App::setLocale($originalLocale);
        }
    }
}
