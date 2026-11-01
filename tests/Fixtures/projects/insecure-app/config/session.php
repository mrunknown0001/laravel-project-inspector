<?php

return [
    'driver' => env('SESSION_DRIVER', 'file'),
    'lifetime' => env('SESSION_LIFETIME', 120),
    'encrypt' => false,
    'secure' => env('SESSION_SECURE_COOKIE'),
    'http_only' => false,
    'same_site' => 'none',
];
