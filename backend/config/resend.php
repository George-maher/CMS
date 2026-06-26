<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Resend API Key
    |--------------------------------------------------------------------------
    |
    | This value is the API key for Resend.com. It is used by the
    | Resend mailer transport to send emails. Keep this value secure.
    |
    */

    'api_key' => env('RESEND_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Default From Address
    |--------------------------------------------------------------------------
    |
    | The default "from" address for all emails sent through Resend.
    | This should be a verified domain in your Resend account.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@churchmanager.app'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Church Management System')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Configuration
    |--------------------------------------------------------------------------
    |
    */

    'reply_to' => [
        'address' => env('MAIL_REPLY_TO_ADDRESS', env('MAIL_FROM_ADDRESS')),
        'name' => env('MAIL_REPLY_TO_NAME', env('MAIL_FROM_NAME')),
    ],

];
