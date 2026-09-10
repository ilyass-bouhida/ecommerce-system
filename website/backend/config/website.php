<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auth microservice integration (HTTP + JSON only)
    |--------------------------------------------------------------------------
    |
    | Website never reads auth_db. It forwards the browser's auth_token to
    | Auth's introspection endpoint. Inside Docker use the host gateway;
    | production will use its own service URL.
    |
    */

    'auth_internal_url' => env('AUTH_INTERNAL_URL', 'http://host.docker.internal:8001'),

    'auth_internal_key' => env('AUTH_INTERNAL_KEY', ''),

    'auth_timeout' => 5,

    'auth_connect_timeout' => 2,

    /*
    |--------------------------------------------------------------------------
    | Outbound website HTTP safety (seconds)
    |--------------------------------------------------------------------------
    */

    'http_timeout' => (int) env('WEBSITE_HTTP_TIMEOUT', 5),

    'http_connect_timeout' => (int) env('WEBSITE_HTTP_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Webhook replay tolerance (seconds)
    |--------------------------------------------------------------------------
    */

    'webhook_timestamp_tolerance' => (int) env('WEBHOOK_TIMESTAMP_TOLERANCE', 300),

];
