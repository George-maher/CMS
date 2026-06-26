<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $dbStatus = 'connected';
    } catch (\Exception $e) {
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
