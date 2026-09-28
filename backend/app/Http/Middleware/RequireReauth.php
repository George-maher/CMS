<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class RequireReauth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        /** @var \App\Models\User $user */
        /** @var string|null $password */
        /** @var string|null $password */
        $password = $request->input('password');

        if ($password && Hash::check($password, $user->password)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        /** @var string|null $reauthToken */
        /** @var string|null $reauthToken */
        $reauthToken = $request->header('X-Reauth-Token');
        if ($reauthToken && $password) {
            $expected = hash_hmac('sha256', 'reauth:' . $user->id, $password);
            if (hash_equals($expected, $reauthToken)) {
                /** @var Response $response */
                $response = $next($request);

                return $response;
            }
        }

        return response()->json([
            'message' => __('auth.reauth_required'),
            'reauth_required' => true,
        ], 401);
    }
}
