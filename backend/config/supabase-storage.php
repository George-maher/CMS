<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supabase Storage
    |--------------------------------------------------------------------------
    |
    | Native Supabase Storage API configuration.
    | Uses REST API directly — no S3-compatible layer.
    |
    */

    'project_url' => env('SUPABASE_URL'),

    'anon_key' => env('SUPABASE_ANON_KEY'),

    'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),

    'base_url' => env('SUPABASE_STORAGE_URL'),

    'max_image_size' => (int) env('SUPABASE_MAX_IMAGE_SIZE', 5120),

    'max_document_size' => (int) env('SUPABASE_MAX_DOCUMENT_SIZE', 10240),

    /*
    |--------------------------------------------------------------------------
    | Storage Buckets
    |--------------------------------------------------------------------------
    |
    | Buckets that are automatically created via the supabase:create-buckets
    | command or the storage migration.
    |
    */
    'buckets' => [
        'profiles' => [
            'name' => 'profiles',
            'public' => true,
        ],
        'events' => [
            'name' => 'events',
            'public' => true,
        ],
        'documents' => [
            'name' => 'documents',
            'public' => false,
        ],
        'ids' => [
            'name' => 'ids',
            'public' => false,
        ],
        'attachments' => [
            'name' => 'attachments',
            'public' => false,
        ],
    ],

];
