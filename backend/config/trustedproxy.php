<?php

use Illuminate\Http\Request;

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Set the proxy IP addresses that are trusted. Use '*' to trust all
    | proxies (common when running behind nginx in Docker/Kubernetes).
    |
    | Supported: null, '*' (all proxies), or an array of IPs
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '*'),

    /*
    |--------------------------------------------------------------------------
    | Headers
    |--------------------------------------------------------------------------
    |
    | The headers that should be used to detect the user's original IP and
    | host. When using AWS/GCP/Azure or nginx, the defaults below work
    | correctly.
    |
    */

    'headers' => Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB,

];
