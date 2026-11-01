<?php

return [
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => 'Live-Literal-Secret-77aa',
    ],
    'mailgun' => [
        'secret' => env('MAILGUN_SECRET', 'mg-Default-Secret-123'),
    ],
    'assistant' => [
        'api_key' => 'ntl_live_FixtureFakeKey123456',
    ],
    'cache_url' => 'redis://default:Redis-Pass-55@cache.internal:6379',
];
