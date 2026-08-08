<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApproval
{
    /**
     * Endpoints a pending/rejected applicant may reach with their authenticated
     * token. Everything else is strictly forbidden until the application is
     * approved — enforced here server-side, never only in the frontend.
     *
     * @var array<int, string>
     */
    private const RESTRICTED_ACCESS_WHITELIST = [
        'api/v1/auth/me',
        'api/v1/auth/logout',
        'api/v1/application/status',
        'api/v1/pending/status',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        /** @var User $user */
        if ($user->isApproved()) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        foreach (self::RESTRICTED_ACCESS_WHITELIST as $path) {
            if ($request->is($path)) {
                /** @var Response $response */
                $response = $next($request);

                return $response;
            }
        }

        return response()->json([
            'message' => 'Your account is pending approval. You cannot access this resource until your application is approved.',
            'code' => 'APPLICATION_PENDING',
        ], 403);
    }
}
