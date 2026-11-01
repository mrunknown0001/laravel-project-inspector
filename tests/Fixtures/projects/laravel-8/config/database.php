<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
        ],
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'password' => env('DB_PASSWORD', ''),
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'password' => env('DB_PASSWORD', ''),
        ],
        'legacy' => [
            'driver' => 'sqlsrv',
            'host' => env('LEGACY_DB_HOST', 'localhost'),
        ],
    ],
    'migrations' => 'migrations',
];
