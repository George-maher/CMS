<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'platform_admin_login_path' => env('PLATFORM_ADMIN_LOGIN_PATH', 'platform-secure-admin-login'),

    /*
    |--------------------------------------------------------------------------
    | Supabase
    |--------------------------------------------------------------------------
    |
    | Native Supabase Storage API configuration.
    | Uses REST API directly — no S3-compatible layer.
    |
    */

    'supabase' => [
        'url' => env('SUPABASE_URL'),
        'anon_key' => env('SUPABASE_ANON_KEY'),
        'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
        'storage_url' => env('SUPABASE_STORAGE_URL'),
    ],

];
