<?php

namespace App\Http\Middleware;

use App\Models\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $requiredPermissions = [];
        foreach ($permissions as $perm) {
            foreach (explode(',', $perm) as $p) {
                $requiredPermissions[] = trim($p);
            }
        }

        if (empty($requiredPermissions)) {
            return $next($request);
        }

        foreach ($requiredPermissions as $permission) {
            if (Permission::userHasPermission($user, $permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Forbidden. You do not have the required permission.',
        ], 403);
    }
}
