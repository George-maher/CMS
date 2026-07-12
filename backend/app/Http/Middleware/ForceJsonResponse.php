<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        if (str_contains($request->path(), 'platform-secure-admin-login')) {
            Log::info('[DEBUG] ForceJsonResponse — platform login request', [
                'method' => $request->method(),
                'path' => $request->path(),
                'full_url' => $request->fullUrl(),
                'ip' => $request->ip(),
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
