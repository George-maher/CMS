<?php

namespace App\Http\Middleware;

use App\Models\Permission;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        /** @var User $user */
        $requiredPermissions = [];
        foreach ($permissions as $perm) {
            foreach (explode(',', $perm) as $p) {
                $requiredPermissions[] = trim($p);
            }
        }

        if (empty($requiredPermissions)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        foreach ($requiredPermissions as $permission) {
            if (Permission::userHasPermission($user, $permission)) {
                /** @var Response $response */
                $response = $next($request);

                return $response;
            }
        }

        return response()->json([
            'message' => 'Forbidden. You do not have the required permission.',
        ], 403);
    }
}
