<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $dbStatus = 'connected';
    } catch (Exception $e) {
        $dbStatus = 'disconnected';
    }

    $healthy = $dbStatus === 'connected';

    // Fail closed for monitoring: a degraded dependency must produce a
    // non-2xx status so load balancers/orchestrators pull the instance.
    return response()->json([
        'status' => $healthy ? 'healthy' : 'degraded',
        'service' => 'Church Manager API',
        'version' => '1.0.0',
        'database' => $dbStatus,
        'timestamp' => now()->toISOString(),
    ], $healthy ? 200 : 503);
});

/*
|--------------------------------------------------------------------------
| Storage File Serving — Symlink-Independent Fallback
|--------------------------------------------------------------------------
|
| When the public/storage symlink does not exist (e.g., on Windows without
| developer mode or in CI), files uploaded to storage/app/public/ are not
| directly accessible from the web root. This route serves them through
| Laravel as a transparent fallback.
|
| In production with Nginx, the ^~ /storage/ alias block in the Nginx
| config takes precedence and serves files directly — this route is never
| hit, so there is zero performance cost.
|
*/
Route::get('/storage/{path}', function (string $path) {
    // Realpath containment: the resolved file must live inside
    // storage/app/public. This refuses `..` traversal, absolute paths and
    // symlink escapes before the file is ever opened.
    $base = realpath(storage_path('app/public'));
    $fullPath = $base !== false ? realpath($base.DIRECTORY_SEPARATOR.ltrim($path, '/\\')) : false;

    if ($base === false
        || $fullPath === false
        || ! str_starts_with($fullPath, $base.DIRECTORY_SEPARATOR)
        || is_dir($fullPath)) {
        abort(404);
    }

    $mime = mime_content_type($fullPath) ?: 'application/octet-stream';

    return response()->file($fullPath, [
        'Content-Type' => $mime,
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=2592000, immutable',
    ]);
})->where('path', '.*');
