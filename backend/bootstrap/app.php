<?php

use App\Http\Middleware\CheckApproval;
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
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
            'track.activity' => TrackActivity::class,
            'reauth' => RequireReauth::class,
        ]);

        $middleware->api(prepend: [
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

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                /** @var array<string, mixed> $headers */
                $headers = $e->getHeaders();
                $retryAfter = $headers['Retry-After'] ?? 60;

                /** @var User|null $user */
                $user = $request->user();
                Log::warning('Rate limit exceeded', [
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

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                /** @var User|null $errorUser */
                $errorUser = $request->user();
                Log::error('Unhandled API exception', [
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
