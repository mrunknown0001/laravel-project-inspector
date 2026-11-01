<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'password' => env('DB_PASSWORD', ''),
        ],
    ],
];
