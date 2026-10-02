<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        /** @var User $user */
        $allowedRoles = [];
        foreach ($roles as $role) {
            // Support comma-separated roles passed as single string: "admin,servant"
            foreach (explode(',', $role) as $r) {
                $allowedRoles[] = trim($r);
            }
        }

        if (! in_array($user->role->value, $allowedRoles, true)) {
            return response()->json(['message' => 'Forbidden. You do not have the required role.'], 403);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
