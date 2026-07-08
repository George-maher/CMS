<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | When using Supabase Storage (SUPABASE_URL + SUPABASE_SERVICE_ROLE_KEY set),
    | the actual storage is handled by SupabaseStorageService via REST API.
    | This disk setting only applies to Laravel's filesystem operations.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/') . '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Local Fallback for Supabase Storage
        |--------------------------------------------------------------------------
        |
        | When Supabase is not configured, uploaded files are stored here
        | and served via the public/storage symlink.
        |
        */

        'local_uploads' => [
            'driver' => 'local',
            'root' => storage_path('app/public/uploads'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/') . '/storage/uploads',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
