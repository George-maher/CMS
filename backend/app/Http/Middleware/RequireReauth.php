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

        $password = $request->input('password');

        if ($password && Hash::check($password, $user->password)) {
            return $next($request);
        }

        $reauthToken = $request->header('X-Reauth-Token');
        if ($reauthToken && $password) {
            $expected = hash_hmac('sha256', 'reauth:' . $user->id, $password);
            if (hash_equals($expected, $reauthToken)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => __('auth.reauth_required'),
            'reauth_required' => true,
        ], 401);
    }
}
