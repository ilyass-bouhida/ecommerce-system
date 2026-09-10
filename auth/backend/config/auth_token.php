<?php

return [

    'cookie' => env('AUTH_COOKIE_NAME', 'auth_token'),

    'ttl_minutes' => (int) env('AUTH_TOKEN_TTL_MINUTES', 720),

    'internal_key' => env('INTERNAL_API_KEY', ''),

];
