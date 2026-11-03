/**
 * "Install the app" (D2-PWA follow-up): the header link, the banner in the app
 * layout and the settings page (settings.app) all read this one Alpine
 * component, `x-data="pwaInstall"`.
 *
 * Chromium's own prompt is captured before any of this runs, by the inline
 * script in partials/head.blade.php: on `beforeinstallprompt` it calls
 * preventDefault(), keeps the event in `window.__hopehrInstallPrompt` and
 * dispatches `hopehr:installable` on window; on `appinstalled` it clears the
 * event and dispatches `hopehr:installed`. This file only reads that contract,
 * so a page that loads late (or arrives through wire:navigate) still finds an
 * event that fired long before it.
 *
 * Only Chromium browsers offer a prompt. Everywhere else the page shows the
 * steps for the browser's own menu, and the Install button is never shown
 * without a real prompt behind it.
 */

export const DISMISS_KEY = 'hopehr-install-dismissed';
const DISMISS_FOR_MS = 30 * 24 * 60 * 60 * 1000; // 30 days

/**
 * Where the visitor is, for the instructions:
 *   ios              iPhone / iPad in Safari (Share → Add to Home Screen)
 *   ios-non-safari   iPhone / iPad in Chrome, Edge, Firefox… (Share menu on iOS 16.4+, else Safari)
 *   android          Android in Chrome, Edge, Samsung Internet… (prompt or menu)
 *   firefox-android  Android Firefox (menu → Install / Add to Home screen)
 *   firefox          desktop Firefox (cannot install web apps)
 *   desktop-chromium Windows / macOS / Linux in Chrome, Edge, Brave… (prompt or address bar)
 *   desktop-safari   macOS Safari 17+ (File → Add to Dock)
 *   other            anything else
 */
export function detectPlatform(nav = navigator) {
    const ua = nav.userAgent || '';

    // iPadOS 13+ reports itself as a Mac; a Mac has no multi-touch screen.
    const ios = /iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && (nav.maxTouchPoints || 0) > 1);
    if (ios) return /CriOS|FxiOS|EdgiOS|OPiOS|GSA\//.test(ua) ? 'ios-non-safari' : 'ios';

    const firefox = /Firefox\//.test(ua);
    if (/Android/i.test(ua)) return firefox ? 'firefox-android' : 'android';
    if (firefox) return 'firefox';

    const chromium = /Chrome\/|Chromium\/|Edg\//.test(ua)
        || Boolean(nav.userAgentData?.brands?.some((b) => /Chromium/.test(b.brand)));
    if (chromium) return 'desktop-chromium';

    if (/Macintosh/.test(ua) && /Version\/[\d.]+.*Safari\//.test(ua)) return 'desktop-safari';

    return 'other';
}

/** Running as the installed app: its own window, not a browser tab. */
export function isStandalone() {
    try {
        if (window.matchMedia?.('(display-mode: standalone)').matches) return true;
    } catch {
        // Very old engines without matchMedia: fall through to the iOS flag.
    }

    return window.navigator.standalone === true; // iOS home-screen app
}

// Storage may throw (private mode, blocked site data) or come back empty: the
// banner then simply shows again, which is the safe direction.
export function isDismissed(now = Date.now()) {
    try {
        const at = Number(window.localStorage.getItem(DISMISS_KEY));

        return Number.isFinite(at) && at > 0 && now - at < DISMISS_FOR_MS;
    } catch {
        return false;
    }
}

function rememberDismissal() {
    try {
        window.localStorage.setItem(DISMISS_KEY, String(Date.now()));
    } catch {
        // Not remembered: it is hidden for this page only.
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pwaInstall', () => ({
        installed: false,
        canPrompt: false,
        platform: 'other',
        dismissed: false,
        busy: false,
        outcome: null, // 'accepted' | 'dismissed' | 'failed' after install()

        init() {
            this.platform = detectPlatform();
            this.dismissed = isDismissed();
            this.refresh();

            // Kept on the component so destroy() removes exactly these: each
            // wire:navigate tears the old tree down and mounts a fresh one, so
            // nothing accumulates on window.
            this.onInstallable = () => this.refresh();
            this.onInstalled = () => {
                this.installed = true;
                this.canPrompt = false;
                this.outcome = 'accepted';
            };
            window.addEventListener('hopehr:installable', this.onInstallable);
            window.addEventListener('hopehr:installed', this.onInstalled);

            try {
                this.displayMode = window.matchMedia('(display-mode: standalone)');
                this.displayMode.addEventListener?.('change', this.onInstallable);
            } catch {
                this.displayMode = null;
            }
        },

        destroy() {
            window.removeEventListener('hopehr:installable', this.onInstallable);
            window.removeEventListener('hopehr:installed', this.onInstalled);
            this.displayMode?.removeEventListener?.('change', this.onInstallable);
        },

        refresh() {
            this.installed = isStandalone();
            this.canPrompt = !this.installed && Boolean(window.__hopehrInstallPrompt);
        },

        // 'installed' | 'ready' (a real prompt) | 'manual' (browser menu) | 'unsupported'
        get status() {
            if (this.installed) return 'installed';
            if (this.canPrompt) return 'ready';

            return this.platform === 'firefox' ? 'unsupported' : 'manual';
        },

        // The banner: only where one tap (or the iOS Share steps) gets there.
        get showBanner() {
            return !this.installed && !this.dismissed && (this.canPrompt || this.platform === 'ios');
        },

        async install() {
            const event = window.__hopehrInstallPrompt;
            if (!event || this.busy) {
                this.canPrompt = false;
                return;
            }

            this.busy = true;
            this.outcome = null;
            try {
                await event.prompt();
                const choice = await event.userChoice;
                this.outcome = choice?.outcome === 'accepted' ? 'accepted' : 'dismissed';
                // Declining the browser's own window is an answer: no banner for 30 days.
                if (this.outcome === 'dismissed') this.dismiss();
            } catch {
                this.outcome = 'failed';
            } finally {
                // A prompt event can be used once; Chromium sends a new
                // beforeinstallprompt (hopehr:installable) if it may ask again.
                window.__hopehrInstallPrompt = null;
                this.canPrompt = false;
                this.busy = false;
            }
        },

        dismiss() {
            this.dismissed = true;
            rememberDismissal();
        },
    }));
});
