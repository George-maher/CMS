<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for cross-origin requests. The API is consumed by the frontend
    | SPA which may be served from a different origin in production.
    |
    | For multiple frontends, set CORS_ALLOWED_ORIGINS as a comma-separated
    | list in your .env file:
    |   CORS_ALLOWED_ORIGINS=https://app1.example.com,https://app2.example.com
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        env('FRONTEND_URL', 'http://localhost:3000')
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => env('CORS_MAX_AGE', 86400),

    'supports_credentials' => true,

];
