/*
 * HopeHR service worker (D2-PWA; DEC-07: installable, without offline caching
 * of private data).
 *
 * What it stores, and nothing else:
 *   - the offline page and the app icons (STATIC, below), and
 *   - Vite's fingerprinted build files under /build/assets/ (public CSS, JS,
 *     fonts; the name changes with every release, so a cached copy is never
 *     stale).
 *
 * What it never stores: any page (HTML), any Livewire request or response,
 * photos (/employee-photo), files (/employee-files), the digital ID, or any
 * other response. Pages always come from the network; when the network is
 * down, a navigation gets the static offline page instead.
 *
 * Writes are never touched: only GET requests are handled at all, so a save
 * made without a connection fails at once and is never queued or replayed
 * when the connection comes back (no Background Sync).
 *
 * Push (docs/notifications/architecture.md): the server sends a message to
 * this browser's push service, encrypted end to end to this device's keys; the
 * push service sees only ciphertext. The worker decrypts nothing itself (the
 * browser does) and stores nothing: it shows the notification it is given
 * (title, text, link; generic text when the person turned previews off), tells
 * open pages so the bell refreshes, and on a click opens the link, same origin
 * only. Every push shows a notification (userVisibleOnly). When the browser
 * rotates the subscription, the worker reports the new one to /push/renew,
 * proving ownership with the old one's auth secret.
 *
 * Updates: the page registers this file with updateViaCache 'none' and asks
 * for an update check on every full load, and public/.htaccess serves it with
 * Cache-Control: no-cache, so a new release reaches devices on their next visit.
 */
// Bump on any change to this file or to STATIC: the browser installs the new
// worker, and its activate step drops the old version's cache.
const VERSION = 'hopehr-static-v3';
const OFFLINE_URL = '/offline.html';
const STATIC = [
    OFFLINE_URL,
    '/favicons/android-chrome-192x192.png',
    '/favicons/android-chrome-512x512.png',
    '/favicons/favicon-32x32.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(STATIC)).then(() => self.skipWaiting()));
});

// Drop every cache this worker does not own, including those of older versions.
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

// The page asks for a clean slate on sign-out and on a role switch.
self.addEventListener('message', (event) => {
    if (event.data === 'hopehr:clear') {
        event.waitUntil(caches.delete(VERSION).then(() => caches.open(VERSION)).then((cache) => cache.addAll(STATIC)));
    }
});

const isBuildAsset = (url) => url.origin === self.location.origin && url.pathname.startsWith('/build/assets/');

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return; // Writes go straight to the network, or fail; never stored, never replayed.
    }

    const url = new URL(request.url);

    if (request.mode === 'navigate') {
        // Network only. The cached offline page is the one fallback; the page itself is never stored.
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));

        return;
    }

    if (isBuildAsset(url)) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                if (response.ok && response.type === 'basic') {
                    const copy = response.clone();
                    caches.open(VERSION).then((cache) => cache.put(request, copy));
                }

                return response;
            })),
        );
    }

    // Everything else (API, Livewire, photos, files, icons served elsewhere) is left to the browser.
});

/* ---- Web Push ------------------------------------------------------------ */

const FALLBACK = { title: 'HopeHR', body: 'You have a new notification.' };
const ICON = '/favicons/android-chrome-192x192.png';
const BADGE = '/favicons/favicon-32x32.png';

// Only this site's pages may be opened from a notification.
const sameOriginUrl = (value) => {
    try {
        const url = new URL(value || '/', self.location.origin);

        return url.origin === self.location.origin ? url.href : new URL('/', self.location.origin).href;
    } catch {
        return new URL('/', self.location.origin).href;
    }
};

const text = (value, fallback) => (typeof value === 'string' && value.trim() !== '' ? value : fallback);

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = {}; // Unreadable payload: still show something (userVisibleOnly).
    }
    if (!data || typeof data !== 'object') data = {};

    const tag = typeof data.tag === 'string' && data.tag !== '' ? data.tag : undefined;
    const options = {
        body: text(data.body, FALLBACK.body),
        icon: text(data.icon, ICON),
        badge: text(data.badge, BADGE),
        data: { url: sameOriginUrl(data.url) },
        silent: data.silent === true,
        requireInteraction: data.urgent === true,
    };
    if (tag) {
        options.tag = tag;
        options.renotify = true;
    }

    event.waitUntil(Promise.all([
        self.registration.showNotification(text(data.title, FALLBACK.title), options),
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((windows) => windows.forEach((client) => client.postMessage({ type: 'hopehr:push' })))
            .catch(() => {}),
    ]));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = sameOriginUrl(event.notification.data?.url);

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const client = windows.find((w) => new URL(w.url).origin === self.location.origin);
            if (client) {
                return client.focus().then((focused) => (focused || client).navigate?.(url)).catch(() => self.clients.openWindow(url));
            }

            return self.clients.openWindow(url);
        }),
    );
});

const base64url = (buffer) => {
    if (!buffer) return '';
    const bytes = new Uint8Array(buffer);
    let binary = '';
    bytes.forEach((b) => { binary += String.fromCharCode(b); });

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

// The browser replaced (or dropped) the subscription. Without the old one there is
// nothing to prove ownership with; the next enable from the settings page fixes it.
self.addEventListener('pushsubscriptionchange', (event) => {
    const old = event.oldSubscription;
    if (!old) return;

    event.waitUntil((async () => {
        try {
            const subscription = event.newSubscription || await self.registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: old.options?.applicationServerKey,
            });

            await fetch('/push/renew', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    old_endpoint: old.endpoint,
                    old_auth: base64url(old.getKey && old.getKey('auth')),
                    subscription: subscription.toJSON(),
                }),
            });
        } catch {
            // Fail silently: the server drops the old one when the push service reports it gone.
        }
    })());
});
