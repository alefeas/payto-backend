<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:3001',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'https://payto.vercel.app',
        'https://payto-frontend.vercel.app',
        'https://payto-frontend-git-main-alefeas-projects.vercel.app',
    ],
    'allowed_origins_patterns' => [
        '#^http://localhost.*#',
        '#^http://127\.0\.0\.1.*#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['*'],
    'max_age' => 86400,
    'supports_credentials' => true,
];
