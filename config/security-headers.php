<?php

$environment = env('APP_ENV', 'production');

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | HSTS is enabled by default outside local/test environments. Disable it
    | only while an environment is intentionally available over plain HTTP.
    |
    */
    'hsts' => [
        'enabled' => env(
            'SECURITY_HEADERS_HSTS',
            ! in_array($environment, ['local', 'testing'], true),
        ),
        'value' => 'max-age=63072000; includeSubDomains; preload',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Production enforces CSP unless an operator explicitly enables a staged
    | report-only rollout. Local and test environments default to report-only
    | so development tooling is not unexpectedly blocked.
    |
    */
    'csp' => [
        'report_only' => env(
            'CSP_REPORT_ONLY',
            in_array($environment, ['local', 'testing'], true),
        ),
        'directives' => [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'none'"],
            'form-action' => ["'self'"],
            'script-src' => [
                "'self'",
                // Alpine.js (and Livewire's own directive evaluator) compiles
                // x-data/x-on/wire: expressions into `new Function(...)` at
                // runtime. Without this, every Alpine/Livewire directive in
                // the app throws an EvalError and silently no-ops.
                "'unsafe-eval'",
                'https://cdnjs.cloudflare.com',
                'https://cdn.jsdelivr.net',
                'https://esm.sh',
                'https://unpkg.com',
            ],
            'style-src' => [
                "'self'",
                "'unsafe-inline'",
                'https://cdnjs.cloudflare.com',
                'https://cdn.jsdelivr.net',
            ],
            'font-src' => [
                "'self'",
                'data:',
                'https://cdnjs.cloudflare.com',
            ],
            'img-src' => [
                "'self'",
                'data:',
                'blob:',
                'https://ui-avatars.com',
                'https://randomuser.me',
            ],
            'media-src' => ["'self'", 'blob:'],
            'connect-src' => ["'self'", 'ws:', 'wss:'],
            'frame-src' => ["'self'"],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
            'upgrade-insecure-requests' => [],
        ],
    ],
];
