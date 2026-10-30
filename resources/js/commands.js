/**
 * Prompt 10: commands from the Ctrl+K box, the "g then a letter" keys and the
 * "?" list of shortcuts (<x-keyboard-shortcuts>).
 *
 * Which pages the keys open is decided on the server for the active role
 * (App\Support\KeyboardShortcuts) and handed to the component; this file only
 * listens. When a key press is a shortcut at all is shortcuts-guard.js.
 */

import { IDLE, isModifierKey, isSheetKey, letterOf, shouldIgnore, step } from './shortcuts-guard.js';

/**
 * Light / dark, exactly as the header switch does it (<x-theme-toggle>): the
 * choice is remembered on this device under "hopehr-theme", applied as
 * <html data-theme>, and announced with the `hopehr-theme` window event, which
 * every switch on the page listens to so they all show the new state.
 */
export function toggleTheme() {
    const root = document.documentElement;
    const dark = root.dataset.theme ? root.dataset.theme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = dark ? 'light' : 'dark';

    try { localStorage.setItem('hopehr-theme', theme); } catch (e) {}
    root.dataset.theme = theme;
    window.dispatchEvent(new CustomEvent('hopehr-theme', { detail: theme }));

    return theme;
}

/** As the header's New menu does: the screen's Cancel comes back to this page (SafeReturn checks it). */
function withReturn(href) {
    const url = new URL(href, location.href);
    const here = new URLSearchParams(location.search);
    here.delete('from');
    const query = here.toString();
    url.searchParams.set('from', location.pathname + (query ? '?' + query : ''));

    return url.toString();
}

// A command picked in the Ctrl+K box (livewire/layout/global-search.blade.php).
window.addEventListener('hopehr-command', (event) => {
    const { action, href, returns } = event.detail ?? {};

    switch (action) {
        case 'theme':
            toggleTheme();
            break;
        case 'shortcuts':
            window.dispatchEvent(new CustomEvent('shortcuts-open'));
            break;
        case 'locale':
            // A full load, as the header's EN | AR links: the whole page changes language and direction.
            if (href) window.location.assign(href);
            break;
        case 'open':
            if (href) Livewire.navigate(returns ? withReturn(href) : href);
            break;
    }
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('keyboardShortcuts', (goTo = {}) => ({
        open: false,
        sequence: IDLE,

        // On window: every key press on the page passes here first.
        onKey(event) {
            if (this.open || isModifierKey(event) || shouldIgnore(event)) return;

            if (isSheetKey(event)) {
                event.preventDefault();
                this.show();
                return;
            }

            const { state, go } = step(this.sequence, letterOf(event), Date.now());
            this.sequence = state;

            if (go && goTo[go]) {
                event.preventDefault();
                Livewire.navigate(goTo[go]);
            }
        },

        show() {
            this.sequence = IDLE;
            this.open = true;
        },

        // x-trap hands focus back to what had it before the list opened.
        close() {
            this.open = false;
        },
    }));
});
