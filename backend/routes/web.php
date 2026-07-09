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

    return response()->json([
        'status' => $dbStatus === 'connected' ? 'healthy' : 'degraded',
        'service' => 'Church Manager API',
        'version' => '1.0.0',
        'database' => $dbStatus,
        'timestamp' => now()->toISOString(),
    ]);
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
    $fullPath = storage_path('app/public/'.$path);

    if (! file_exists($fullPath) || is_dir($fullPath)) {
        abort(404);
    }

    $mime = mime_content_type($fullPath) ?: 'application/octet-stream';

    return response()->file($fullPath, [
        'Content-Type' => $mime,
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=2592000, immutable',
    ]);
})->where('path', '.*');
