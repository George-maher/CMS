<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a correlation id to every request and echoes it back.
 *
 * Why
 * ---
 * Without one, a single user-reported failure cannot be followed through the
 * system. The only shared keys available are the IP address (shared by every
 * user behind one NAT, and spoofable via X-Forwarded-For) and the user id
 * (absent for failed logins, which are exactly the interesting ones). So an
 * operator cannot answer "which requests did this one person make?", and
 * cannot tie a 500 to the specific row that caused it.
 *
 * This is deliberately a correlation id, not distributed tracing. There is no
 * span export, no OpenTelemetry dependency, and no timing waterfall.
 *
 * Trusting the inbound value
 * -------------------------
 * A client-supplied id IS honoured, because a front-end that generates an id
 * per user action is the only way to correlate a click with a backend error
 * across the network boundary. It is validated first, because an unvalidated
 * header is log injection: a caller could otherwise insert newlines and forge
 * log lines, or push an unbounded string into every log record.
 *
 * Accepted shape: 1-64 chars of [A-Za-z0-9._-]. Anything else is discarded and
 * replaced. That permits the ULID/UUID forms that browsers, proxies and
 * tracing SDKs all emit, and rejects everything else.
 *
 * Secrets never enter the id: it is validated to a safe character set and is
 * never derived from, or combined with, a token, email or user id.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * Applied via Log::withContext() so every log line emitted during the
     * request carries the id, without each call site having to remember it.
     */
    private static ?string $current = null;

    public static function current(): ?string
    {
        return self::$current;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolve($request);

        self::$current = $requestId;
        $request->attributes->set('request_id', $requestId);

        Log::withContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function resolve(Request $request): string
    {
        $incoming = $request->header(self::HEADER);

        if (is_string($incoming) && $this->isSafe($incoming)) {
            return $incoming;
        }

        return (string) Str::ulid();
    }

    private function isSafe(string $value): bool
    {
        // Length first: the regex below is linear, but an unbounded subject is
        // a cheap way to burn CPU on every request.
        if ($value === '' || strlen($value) > 64) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }
}
