<?php

return [

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

        'supabase' => [
            'driver' => 'supabase',
            'project_url' => env('SUPABASE_URL'),
            'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
            'base_url' => env('SUPABASE_STORAGE_URL'),
        ],

        'profiles' => [
            'driver' => 'local',
            'root' => storage_path('app/profiles'),
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
