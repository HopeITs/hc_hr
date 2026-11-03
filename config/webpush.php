<?php

/*
 * Web Push (VAPID). Generate a key pair once per environment with
 * `php artisan webpush:vapid` and put it in .env. The private key never leaves
 * the server; the public key is printed into the page for the browser.
 * Changing the pair invalidates every existing subscription (browsers bind a
 * subscription to the public key), so devices must enable push again.
 */
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    // How long the push service keeps a message for an offline device (seconds).
    'ttl' => (int) env('WEBPUSH_TTL', 86400),

    // Consecutive failures (other than "gone") before a subscription is dropped.
    'max_failures' => (int) env('WEBPUSH_MAX_FAILURES', 5),

    // A device not seen for this many days is pruned by push:prune.
    'stale_after_days' => (int) env('WEBPUSH_STALE_AFTER_DAYS', 120),
];
