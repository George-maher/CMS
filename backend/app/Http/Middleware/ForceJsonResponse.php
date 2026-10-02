<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    /**
     * The platform-admin login path is a security control: obscuring it is one
     * of the few things standing between an attacker and the highest-privilege
     * account in the system.
     *
     * This block used to write the path, the full URL and the client IP to the
     * `info` log on every attempt. At `info` level that is shipped to whatever
     * log sink the deployment uses, which routinely has far wider read access
     * than the application itself — so the log became a durable record of a
     * secret that the URL was deliberately hiding. It was also mislabelled
     * `[DEBUG]` while running in production.
     *
     * Debug logging belongs at the `debug` level, which is filtered out unless
     * explicitly enabled, and it must not carry the secret path. Recording the
     * attempt without the path preserves the operational signal (an operator
     * can still see that a platform login was attempted and from where) while
     * removing the disclosure.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        if (str_contains($request->path(), 'platform-secure-admin-login')) {
            Log::debug('Platform admin login attempted', [
                'method' => $request->method(),
                'ip' => $request->ip(),
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
