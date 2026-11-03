/**
 * Web Push on this device (docs/notifications/architecture.md, Browser side).
 *
 *   x-data="pushDevice"  the state of push on this browser and the buttons that
 *                        change it (settings page). Permission is only ever asked
 *                        for, and a subscription only ever made, from a click:
 *                        enable(). Nothing here subscribes on its own.
 *   page-load sync       signed-in pages (meta hc-push-routes present) with
 *                        permission granted and a live subscription report it
 *                        once per page session, so the server keeps keys and
 *                        last-seen current. It never creates a row.
 *   in-app chime         a soft two-tone sound when the bell counts a new
 *                        notification (window `notif-arrived`), if the person's
 *                        sound setting is on (meta hc-notif-sound).
 *   service worker       a push the worker shows is posted to open pages as
 *                        {type: 'hopehr:push'}; the bell is told to refresh now.
 *
 * Errors are codes, not text; the view translates them:
 *   unsupported | insecure | ios_install | not_configured | denied | network | failed
 */
import { detectPlatform, isStandalone } from './pwa-install.js';

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content ?? null;

function routes() {
    try {
        return JSON.parse(meta('hc-push-routes') || 'null');
    } catch {
        return null;
    }
}

const vapidKey = () => meta('hc-vapid-key') || '';

// Livewire puts the token on its script tag too; the meta is the one head.blade.php owns.
const csrf = () => meta('csrf-token') || document.querySelector('script[data-csrf]')?.dataset.csrf || '';

const isSupported = () => 'serviceWorker' in navigator
    && 'PushManager' in window
    && 'Notification' in window
    && window.isSecureContext === true;

const readPermission = () => ('Notification' in window ? Notification.permission : 'unsupported');

class HttpError extends Error {
    constructor(status, code) {
        super(code || `http_${status}`);
        this.status = status;
        this.code = code;
    }
}

async function send(method, url, body) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new HttpError(response.status, data?.error);

    return data;
}

// fetch() rejects with a TypeError only when the request never got an answer.
function errorCode(error) {
    if (error instanceof HttpError) return error.code === 'not_configured' ? 'not_configured' : 'failed';
    if (error instanceof TypeError) return 'network';
    if (error?.name === 'NotAllowedError') return 'denied';

    return 'failed';
}

function urlBase64ToUint8Array(value) {
    const padded = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);

    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

function sameKey(a, b) {
    if (!a || !b) return false;
    const x = new Uint8Array(a);
    if (x.length !== b.length) return false;

    return x.every((byte, i) => byte === b[i]);
}

// The head registers the worker only in a secure context; never wait forever for one.
async function registration() {
    const existing = await navigator.serviceWorker.getRegistration();
    if (existing?.active) return existing;

    return Promise.race([
        navigator.serviceWorker.ready,
        new Promise((_, reject) => setTimeout(() => reject(new Error('no_worker')), 10000)),
    ]);
}

async function currentSubscription() {
    if (!isSupported()) return null;
    const reg = await navigator.serviceWorker.getRegistration();

    return reg ? reg.pushManager.getSubscription() : null;
}

function subscriptionPayload(subscription) {
    const json = subscription.toJSON();
    const encodings = window.PushManager?.supportedContentEncodings || ['aes128gcm'];

    return {
        endpoint: json.endpoint,
        expirationTime: json.expirationTime ? Math.round(json.expirationTime) : null,
        keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
        content_encoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
    };
}

/** "Chrome on Windows" and friends; the person can rename it in the settings. */
export function deviceInfo(nav = navigator) {
    const ua = nav.userAgent || '';
    const hints = nav.userAgentData;
    const brands = (hints?.brands || []).map((b) => b.brand).join(' ');
    const has = (re) => re.test(brands) || re.test(ua);

    let browser = 'Browser';
    if (has(/Edg(e|A|iOS)?\b|Microsoft Edge/)) browser = 'Edge';
    else if (has(/OPR\/|Opera/)) browser = 'Opera';
    else if (has(/SamsungBrowser/)) browser = 'Samsung Internet';
    else if (has(/Firefox\/|FxiOS/)) browser = 'Firefox';
    else if (has(/Google Chrome|Chrome\/|CriOS/)) browser = 'Chrome';
    else if (has(/Chromium/)) browser = 'Chromium';
    else if (/Safari\//.test(ua)) browser = 'Safari';

    const ipad = /iPad/.test(ua) || (/Macintosh/.test(ua) && (nav.maxTouchPoints || 0) > 1);
    const hinted = hints?.platform || '';
    let platform = 'Unknown';
    if (ipad) platform = 'iPadOS';
    else if (/iPhone|iPod/.test(ua)) platform = 'iOS';
    else if (/Android/i.test(hinted) || /Android/i.test(ua)) platform = 'Android';
    else if (/Windows/i.test(hinted) || /Windows/.test(ua)) platform = 'Windows';
    else if (/mac/i.test(hinted) || /Macintosh/.test(ua)) platform = 'macOS';
    else if (/Chrome OS|CrOS/i.test(hinted) || /CrOS/.test(ua)) platform = 'ChromeOS';
    else if (/Linux/i.test(hinted) || /Linux/.test(ua)) platform = 'Linux';

    let type = 'desktop';
    if (ipad || (platform === 'Android' && !/Mobile/.test(ua) && hints?.mobile !== true)) type = 'tablet';
    else if (platform === 'iOS' || platform === 'Android' || hints?.mobile === true) type = 'mobile';

    return {
        name: platform === 'Unknown' ? browser : `${browser} on ${platform}`,
        type,
        browser,
        platform,
        standalone: isStandalone(),
    };
}

// One sync per page session, shared by the page-load check and every pushDevice.
let syncPromise = null;

async function runSync() {
    const urls = routes();
    if (!urls || readPermission() !== 'granted') return { subscribed: false, known: false, id: null, enabled: false };

    const subscription = await currentSubscription();
    if (!subscription) return { subscribed: false, known: false, id: null, enabled: false };

    const result = await send('POST', urls.sync, {
        ...subscriptionPayload(subscription),
        device: { standalone: isStandalone() },
    });

    return { subscribed: true, known: Boolean(result.known), id: result.id ?? null, enabled: Boolean(result.enabled) };
}

function syncOnce(force = false) {
    if (force || !syncPromise) {
        syncPromise = runSync().catch((error) => {
            syncPromise = null; // a failed sync may be retried by the next caller

            throw error;
        });
    }

    return syncPromise;
}

const announce = (id) => window.dispatchEvent(new CustomEvent('push-device-changed', { detail: { id } }));

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pushDevice', () => ({
        supported: false,
        secure: false,
        permission: 'unsupported', // 'default' | 'granted' | 'denied' | 'unsupported'
        standalone: false,
        platform: 'other',
        iosNeedsInstall: false,
        configured: false,
        subscribed: false,
        known: false,
        subscriptionId: null,
        busy: false,
        error: null,
        testResult: null, // 'shown' | 'denied' | 'unsupported' | 'failed' after localTest()

        init() {
            this.platform = detectPlatform();
            this.secure = window.isSecureContext === true;
            this.supported = isSupported();
            this.configured = vapidKey() !== '';
            this.readState();

            // Permission can change in the browser's or the OS's settings while the page is away.
            this.onVisible = () => {
                if (document.visibilityState === 'visible') this.readState();
            };
            // enable()/disable() already updated the shared sync result; no request needed.
            this.onChanged = () => this.refresh();
            document.addEventListener('visibilitychange', this.onVisible);
            window.addEventListener('push-device-changed', this.onChanged);

            this.refresh();
        },

        destroy() {
            document.removeEventListener('visibilitychange', this.onVisible);
            window.removeEventListener('push-device-changed', this.onChanged);
        },

        readState() {
            this.standalone = isStandalone();
            this.iosNeedsInstall = (this.platform === 'ios' || this.platform === 'ios-non-safari') && !this.standalone;
            const before = this.permission;
            this.permission = this.supported ? readPermission() : 'unsupported';
            if (before !== this.permission && before !== 'unsupported') this.refresh(true);
        },

        async refresh(force = false) {
            if (!this.supported || this.permission !== 'granted') {
                this.subscribed = false;
                this.known = false;
                this.subscriptionId = null;

                return;
            }

            try {
                const state = await syncOnce(force);
                this.subscribed = state.subscribed;
                this.known = state.known;
                this.subscriptionId = state.id;
            } catch {
                // Offline or the server is down: what the browser itself knows still holds.
                this.subscribed = Boolean(await currentSubscription().catch(() => null));
            }
        },

        // Why push cannot be switched on here, before anything is asked.
        blocker() {
            if (!this.secure) return 'insecure';
            if (this.iosNeedsInstall) return 'ios_install';
            if (!this.supported) return 'unsupported';
            if (!this.configured) return 'not_configured';
            if (readPermission() === 'denied') return 'denied';

            return null;
        },

        // Only from a click: iOS and recent Chrome refuse a permission request without one,
        // so nothing is awaited before requestPermission().
        async enable() {
            if (this.busy) return;
            this.error = this.blocker();
            if (this.error) {
                this.permission = this.supported ? readPermission() : 'unsupported';

                return;
            }

            this.busy = true;
            try {
                let permission = readPermission();
                if (permission === 'default') {
                    // Older Safari answers through a callback instead of a promise.
                    permission = await new Promise((resolve) => {
                        const asked = Notification.requestPermission(resolve);
                        asked?.then?.(resolve);
                    });
                }
                this.permission = permission;
                if (permission !== 'granted') {
                    // Closing the prompt (still 'default') is not an error.
                    this.error = permission === 'denied' ? 'denied' : null;

                    return;
                }

                const reg = await registration();
                const key = urlBase64ToUint8Array(vapidKey());
                let subscription = await reg.pushManager.getSubscription();

                // Made under another server key (keys rotated): the browser refuses to mix them.
                if (subscription && !sameKey(subscription.options?.applicationServerKey, key)) {
                    await subscription.unsubscribe().catch(() => {});
                    subscription = null;
                }

                subscription ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
                this.subscribed = true;

                const result = await send('POST', routes().store, {
                    ...subscriptionPayload(subscription),
                    device: deviceInfo(),
                });

                this.known = true;
                this.subscriptionId = result.id ?? null;
                syncPromise = Promise.resolve({ subscribed: true, known: true, id: this.subscriptionId, enabled: Boolean(result.enabled) });
                announce(this.subscriptionId);
            } catch (error) {
                this.error = errorCode(error);
            } finally {
                this.busy = false;
            }
        },

        async disable() {
            if (this.busy) return;
            this.busy = true;
            this.error = null;
            const id = this.subscriptionId;
            try {
                const subscription = await currentSubscription();
                if (subscription) {
                    // The server first: a row left behind would keep failing until pruned.
                    await send('DELETE', routes().destroy, { endpoint: subscription.endpoint });
                    await subscription.unsubscribe().catch(() => {});
                }

                this.subscribed = false;
                this.known = false;
                this.subscriptionId = null;
                syncPromise = Promise.resolve({ subscribed: false, known: false, id: null, enabled: false });
                announce(id);
            } catch (error) {
                this.error = errorCode(error);
            } finally {
                this.busy = false;
            }
        },

        // Shows a notification straight from the browser, with no server and no push service:
        // proves the browser and the OS let notifications through on this device.
        async localTest(title = 'HopeHR', body = '') {
            this.testResult = null;
            try {
                if (!this.supported) return (this.testResult = 'unsupported');
                if (readPermission() !== 'granted') return (this.testResult = 'denied');

                const reg = await registration();
                await reg.showNotification(title, {
                    body,
                    icon: '/favicons/android-chrome-192x192.png',
                    badge: '/favicons/favicon-32x32.png',
                    tag: 'hopehr-local-test',
                    data: { url: window.location.href },
                });

                return (this.testResult = 'shown');
            } catch {
                return (this.testResult = 'failed');
            }
        },
    }));
});

// Page-load sync: never asks, never subscribes.
if (routes() && isSupported() && readPermission() === 'granted') {
    syncOnce().catch(() => {});
}

// The worker showed a push: refresh the bell now instead of at its next poll.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'hopehr:push') window.Livewire?.dispatch('notification-created');
    });
}

/*
 * In-app chime. Browsers keep an AudioContext suspended until the page has had a
 * gesture, so the first click or key press unlocks it; before that the chime is
 * simply skipped. The settings page may announce a change with
 * `notif-sound-changed` ({detail: {on}}) so it applies without a reload.
 */
let soundOverride = null;
let audio = null;
let lastChime = 0;

function audioContext() {
    const Context = window.AudioContext || window.webkitAudioContext;
    if (!Context) return null;
    try {
        audio ??= new Context();
    } catch {
        return null;
    }

    return audio;
}

const unlock = () => {
    if (!soundOn()) return;
    audioContext()?.resume?.().catch(() => {});
};
window.addEventListener('pointerdown', unlock, { passive: true });
window.addEventListener('keydown', unlock);

const soundOn = () => (soundOverride ?? meta('hc-notif-sound')) === '1';

window.addEventListener('notif-sound-changed', (event) => {
    soundOverride = event.detail?.on ? '1' : '0';
});

function chime() {
    const ctx = audioContext();
    if (!ctx || ctx.state !== 'running') return;

    const start = ctx.currentTime + 0.02;
    [[659.25, 0], [987.77, 0.13]].forEach(([frequency, offset]) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start + offset);
        gain.gain.exponentialRampToValueAtTime(0.07, start + offset + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.32);
        osc.connect(gain).connect(ctx.destination);
        osc.start(start + offset);
        osc.stop(start + offset + 0.34);
    });
}

window.addEventListener('notif-arrived', () => {
    const now = Date.now();
    if (!soundOn() || document.visibilityState !== 'visible' || now - lastChime < 2000) return;
    lastChime = now;
    try {
        chime();
    } catch {
        // No sound is the safe failure.
    }
});
