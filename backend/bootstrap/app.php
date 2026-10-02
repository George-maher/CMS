<?php

use App\Exceptions\ChurchDeletionException;
use App\Exceptions\LoginFailedException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CheckApproval;
use App\Http\Middleware\EnsureApproval;
use App\Http\Middleware\EnsureEventScope;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RequireReauth;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackActivity;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'approved' => CheckApproval::class,
            'approval' => EnsureApproval::class,
            'event.scope' => EnsureEventScope::class,
            'track.activity' => TrackActivity::class,
            'reauth' => RequireReauth::class,
        ]);

        // AssignRequestId is FIRST so the id exists before anything else can
        // log or short-circuit. ForceJsonResponse can return early (e.g. on a
        // non-JSON Accept header), so the id must be established before it.
        $middleware->api(prepend: [
            AssignRequestId::class,
            ForceJsonResponse::class,
            SetLocale::class,
            'track.activity',
        ]);

        // TrustProxies — required for correct IP/host behind nginx reverse proxy
        $middleware->web(append: [
            TrustProxies::class,
        ]);
        $middleware->api(append: [
            TrustProxies::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                    'code' => 'NOT_FOUND',
                ], 404);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }
        });

        // Authentication failures are a typed contract, not a validation
        // error: the client switches on `code` to pick the recovery action.
        // Registered before the catch-all Throwable handler below.
        $exceptions->render(function (LoginFailedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'code' => $e->failure->value,
                ], $e->failure->status());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'code' => 'UNAUTHORIZED',
                ], 401);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden.',
                    'code' => 'FORBIDDEN',
                ], 403);
            }
        });

        $exceptions->render(function (ChurchDeletionException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'code' => $e->errorCode,
                ], $e->status);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                /** @var array<string, mixed> $headers */
                $headers = $e->getHeaders();
                $retryAfter = $headers['Retry-After'] ?? 60;

                /** @var User|null $user */
                $user = $request->user();
                Log::warning('Rate limit exceeded', [
                    'request_id' => $request->attributes->get('request_id'),
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'user_id' => $user?->id,
                    'user_agent' => $request->userAgent(),
                    'retry_after' => $retryAfter,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => (int) $retryAfter,
                    'code' => 'RATE_LIMITED',
                ], 429, ['Retry-After' => $retryAfter]);
            }
        });

        // PHASE 1C — P0 FIX.
        //
        // Laravel's Handler::render() invokes registered render callbacks
        // (renderViaCallbacks) BEFORE its own
        // ` $e instanceof HttpResponseException => $e->getResponse() ` branch.
        //
        // The named rate limiters declare ->response(...), so ThrottleRequests
        // throws HttpResponseException carrying the intended 429 response.
        // Because HttpResponseException IS a Throwable, the catch-all callback
        // below matched it first, logged "Unhandled API exception" (the wrapped
        // exception has an empty message) and returned 500 — so every one of the
        // 30+ named limiters returned 500 instead of 429.
        //
        // Registering this callback ahead of the catch-all restores the
        // framework's own precedence: return the wrapped response verbatim.
        $exceptions->render(function (HttpResponseException $e) {
            return $e->getResponse();
        });

        // Every OTHER Symfony HttpException — abort(403) from a controller,
        // MethodNotAllowedHttpException from the router, and so on (Phase 1C
        // finding C-4). Application::abort() throws a PLAIN HttpException
        // (Application.php:1440 special-cases only 404), so it matched NONE
        // of the typed callbacks above and fell through to the catch-all
        // Throwable handler below: correct HTTP status, but the body claimed
        // INTERNAL_ERROR / "Internal server error." for an expected 4xx.
        //
        // Registration order is load-bearing: renderViaCallbacks() walks
        // callbacks in order and returns the first non-null response, so the
        // specialised subclasses registered above (NotFound, AccessDenied,
        // Throttle) still win, and this generic slot only sees what they
        // deliberately pass on.
        //
        // 5xx is deferred to the catch-all so genuine server errors keep
        // their error-level Log::error entry, and non-API requests are left
        // to the framework's default HTML error pages.
        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $request->is('api/*') || $e->getStatusCode() >= 500) {
                return null;
            }

            $status = $e->getStatusCode();

            /** @var array<string, string> $headers */
            $headers = $e->getHeaders();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$status] ?? 'Request failed.'),
                'code' => match ($status) {
                    401 => 'UNAUTHORIZED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    405 => 'METHOD_NOT_ALLOWED',
                    419 => 'CSRF_TOKEN_MISMATCH',
                    429 => 'RATE_LIMITED',
                    default => 'HTTP_'.$status,
                },
            ], $status, $headers);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                /** @var User|null $errorUser */
                $errorUser = $request->user();
                Log::error('Unhandled API exception', [
                    'request_id' => $request->attributes->get('request_id'),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'code' => $e->getCode(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                    'user_id' => $errorUser?->id,
                ]);

                $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

                $response = [
                    'success' => false,
                    'message' => 'Internal server error.',
                    'code' => 'INTERNAL_ERROR',
                    'request_id' => $request->attributes->get('request_id'),
                ];

                if (config('app.debug') && app()->isLocal()) {
                    $response['message'] = $e->getMessage();
                    $response['code'] = class_basename($e);
                    $response['file'] = $e->getFile();
                    $response['line'] = $e->getLine();
                    $response['trace'] = explode("\n", $e->getTraceAsString());
                }

                return response()->json($response, $statusCode);
            }
        });
    })->create();
