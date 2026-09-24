<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Services\EventAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEventScope
{
    public function __construct(
        private readonly EventAuthorizationService $eventAuthorization,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $eventId = $request->route('id');
        if (! is_numeric($eventId)) {
            return $next($request);
        }

        /** @var Event|null $event */
        $event = Event::query()->find((int) $eventId);
        if (! $event) {
            return $next($request);
        }

        $user = $request->user();
        if ($user !== null) {
            $this->eventAuthorization->assertCanAccess($user, $event);
        }

        return $next($request);
    }
}
