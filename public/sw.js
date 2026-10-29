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
 */
const VERSION = 'hopehr-static-v1';
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
