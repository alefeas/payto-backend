<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://funky-jonathan-implemented-simon.trycloudflare.com',
        'http://localhost:3000',
        'http://localhost:3001',
    ],
    'allowed_origins_patterns' => [
        '/^https:\/\/.*\.trycloudflare\.com$/',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
