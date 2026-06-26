<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TrackActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $cacheKey = 'user-last-active-' . $user->id;
            $lastActivity = Cache::get($cacheKey);

            $inactivityLimit = config('session.inactivity_timeout', 60); // minutes

            if ($lastActivity && now()->diffInMinutes($lastActivity) > $inactivityLimit) {
                // Token expired due to inactivity — revoke it
                $user->tokens()->where('id', $user->currentAccessToken()->id)->delete();

                return response()->json([
                    'message' => 'Session expired due to inactivity. Please log in again.',
                ], 401);
            }

            Cache::put($cacheKey, now(), now()->addMinutes($inactivityLimit * 2));
        }

        return $next($request);
    }
}
