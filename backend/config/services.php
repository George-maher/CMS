<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Platform Admin
    |--------------------------------------------------------------------------
    */

    'platform_admin_login_path' => env('PLATFORM_ADMIN_LOGIN_PATH', 'platform-secure-admin-login'),

    /*
    |--------------------------------------------------------------------------
    | Support Contact
    |--------------------------------------------------------------------------
    */

    'support_phone' => env('SUPPORT_PHONE', '+201234567890'),
    'support_email' => env('SUPPORT_EMAIL', 'support@churchmanager.app'),

    /*
    |--------------------------------------------------------------------------
    | CORS Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of origins allowed to access the API.
    | Used by config/cors.php.
    |
    */

    'cors_allowed_origins' => env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000')),

];
