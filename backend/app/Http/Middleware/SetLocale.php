<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var string $locale */
        $locale = $request->header('Accept-Language') ?: config('app.locale', 'en');

        if (in_array($locale, ['en', 'ar'], true)) {
            app()->setLocale($locale);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
