<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApproval
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        /** @var User $user */
        if (! $user->isApproved()) {
            return response()->json([
                'message' => 'Your account is pending approval. You cannot perform this action until your application is approved.',
            ], 403);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
