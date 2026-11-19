import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// No Reverb configured (no app key at build time): skip the connection, so a
// build without realtime does not retry a socket on every page.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        // Same prefix as REVERB_SERVER_PATH: Reverb lives under a path on the
        // site's own subdomain rather than on a subdomain of its own.
        wsPath: import.meta.env.VITE_REVERB_PATH ?? '',
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    // Health check: `php artisan reverb:ping <email>` lands here on every page
    // that user has open.
    const userId = document.querySelector('meta[name="user-id"]')?.content;

    if (userId) {
        window.Echo.private(`App.Models.User.${userId}`).listen('.reverb.ping', (event) => {
            console.info('[reverb] ping received', event);
        });
    }
}
