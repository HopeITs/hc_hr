/**
 * The dashboard's workspace (2026-11-09): the person's work stays on the dashboard.
 *
 * Any page the dashboard leads to (View all, Show more, a card's page, a hub page,
 * the sidebar, the header, Ctrl K, a notification) opens here, in a panel over the
 * dashboard, instead of taking the person away. Each page keeps its own server
 * checks and city scoping: it is the page itself, in a frame of this site
 * (data-ws-frame; partials/head.blade.php hides the site's own chrome inside it,
 * SecurityHeaders lets the site frame itself and nobody else).
 *
 *  - several pages at once, as tabs; a link already open is brought to the front;
 *  - the address bar follows (?ws=/en/leaves), so a refresh reopens it and Back
 *    closes it; closing goes back to the dashboard as it was;
 *  - what has to leave stays a normal link: downloads, exports, other sites, a new
 *    tab, sign-out, and the dashboard itself (another language included);
 *  - a page that is not a page of the app (the sign-in page after a session ends)
 *    takes the whole window.
 *
 * Used as x-data="hcWorkspace" (livewire/dashboard/partials/workspace.blade.php).
 */

const MAX_TABS = 8;
// Paths that are files or actions, not pages: they keep their normal behaviour.
const LEAVES = /\/(logout|download|export|export-preview)(\/|$|\?)|\.(pdf|xlsx?|csv|docx?|zip|png|jpe?g)(\?|$)/i;

let seq = 0;

const workspace = () => ({
    tabs: [], // { id, url, title }
    active: null,
    open: false,
    home: '/',
    restoreNavigate: null,

    init() {
        this.home = this.$el.dataset.home || '/';
        const onClick = (e) => this.intercept(e);
        document.addEventListener('click', onClick, true);
        const onPop = () => this.fromAddress(false);
        window.addEventListener('popstate', onPop);
        const onKey = (e) => {
            if (e.key === 'Escape' && this.open && ! e.defaultPrevented) this.closeAll();
        };
        window.addEventListener('keydown', onKey);

        // Every Livewire visit from the dashboard (wire:navigate links, Ctrl K, a card's
        // double-click, the command palette) announces itself first and can be stopped:
        // a page opens here instead. Back and Forward are left alone (detail.history).
        const onNavigate = (e) => {
            const url = e.detail?.url;
            if (! url || e.detail?.history || ! this.wants(url)) return;
            e.preventDefault();
            this.visit(typeof url === 'string' ? url : url.href);
        };
        document.addEventListener('livewire:navigate', onNavigate);
        this.restoreNavigate = () => document.removeEventListener('livewire:navigate', onNavigate);

        // Over the content area exactly: beside the sidebar (open or narrow), under the header.
        const place = () => {
            const shell = document.querySelector('.app-shell')?.getBoundingClientRect();
            const nav = document.getElementById('app-navbar')?.getBoundingClientRect();
            const rtl = document.documentElement.dir === 'rtl';
            const start = shell ? (rtl ? window.innerWidth - shell.right : shell.left) : 0;
            this.$el.style.setProperty('--ws-start', Math.max(0, start) + 'px');
            this.$el.style.setProperty('--ws-top', (nav ? nav.bottom : 0) + 'px');
        };
        place();
        window.addEventListener('resize', place);
        const shellObserver = window.ResizeObserver ? new ResizeObserver(place) : null;
        const shellEl = document.querySelector('.app-shell');
        if (shellObserver && shellEl) shellObserver.observe(shellEl);
        // The page behind does not scroll while the workspace is over it.
        this.$watch('open', (on) => {
            place();
            document.documentElement.classList.toggle('ws-open', on);
        });

        const leave = () => this.teardown();
        document.addEventListener('livewire:navigating', leave, { once: true });
        this.teardown = () => {
            document.removeEventListener('click', onClick, true);
            window.removeEventListener('popstate', onPop);
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('resize', place);
            shellObserver?.disconnect();
            document.documentElement.classList.remove('ws-open');
            this.restoreNavigate?.();
            this.restoreNavigate = null;
        };

        this.fromAddress(true);
    },
    destroy() {
        this.teardown?.();
    },
    teardown: null,

    // ---- which links open here -------------------------------------------
    url(href) {
        try {
            return new URL(href, location.href);
        } catch (e) {
            return null;
        }
    },
    isHome(u) {
        const path = u.pathname.replace(/\/+$/, '') || '/';
        // The dashboard, in any language: /en, /ar, /.
        return path === '/' || /^\/[a-z]{2}$/i.test(path);
    },
    wants(href) {
        const u = this.url(href);
        if (! u || u.origin !== location.origin) return false;
        if (this.isHome(u)) return false;
        if (LEAVES.test(u.pathname)) return false;

        return true;
    },
    intercept(e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const a = e.target instanceof Element ? e.target.closest('a[href]') : null;
        if (! a || a.closest('[data-ws-panel]') || a.hasAttribute('download') || a.dataset.wsSkip !== undefined) return;
        if (a.target && a.target !== '_self') return;
        const href = a.getAttribute('href');
        if (! href || href.startsWith('#') || href.startsWith('javascript:')) return;
        if (! this.wants(a.href)) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        this.visit(a.href);
    },

    // ---- tabs ------------------------------------------------------------
    path(href) {
        const u = this.url(href);

        return u ? u.pathname + u.search + u.hash : href;
    },
    visit(href, push = true) {
        const path = this.path(href);
        const same = this.tabs.find((t) => t.url === path);
        if (same) {
            this.show(same.id, push);

            return;
        }
        if (this.tabs.length >= MAX_TABS) this.tabs.shift();
        const tab = { id: 'ws' + ++seq, url: path, title: '' };
        this.tabs.push(tab);
        this.show(tab.id, push);
    },
    show(id, push = true) {
        this.active = id;
        this.open = true;
        if (push) this.address(true);
        this.$nextTick(() => this.$refs.panel?.focus({ preventScroll: true }));
    },
    close(id) {
        const i = this.tabs.findIndex((t) => t.id === id);
        if (i < 0) return;
        this.tabs.splice(i, 1);
        if (! this.tabs.length) {
            this.closeAll();

            return;
        }
        if (this.active === id) this.active = this.tabs[Math.min(i, this.tabs.length - 1)].id;
        this.address(false);
    },
    closeAll() {
        this.tabs = [];
        this.active = null;
        this.open = false;
        this.address(true);
    },
    current() {
        return this.tabs.find((t) => t.id === this.active) || null;
    },
    // The page as a page of its own, outside the dashboard (the 1% that has to leave).
    pop() {
        const tab = this.current();
        if (tab) window.location.assign(tab.url);
    },

    // ---- the frame tells us where it went ---------------------------------
    frameLoaded(tab, frame) {
        const follow = () => {
            let win;
            try {
                win = frame.contentWindow;
                void win.location.href;
            } catch (e) {
                return; // not this site: nothing to read
            }
            const here = win.location;
            const u = new URL(here.href);
            // Back on the dashboard, from inside: that tab is done.
            if (this.isHome(u)) {
                this.close(tab.id);

                return;
            }
            // Not a page of the app (sign-in after the session ended, an error page): whole window.
            if (! win.document.querySelector('#main-content')) {
                window.location.assign(here.href);

                return;
            }
            tab.url = u.pathname + u.search + u.hash;
            tab.title = (win.document.title || '').replace(/\s+-\s+[^-]+$/, '').trim() || tab.title;
            if (tab.id === this.active) this.address(false);
        };
        follow();
        try {
            frame.contentWindow.document.addEventListener('livewire:navigated', follow);
            frame.contentWindow.addEventListener('popstate', follow);
            // A page inside may also try to leave for the dashboard with Livewire.navigate.
        } catch (e) {
            // ignore
        }
    },

    // ---- the address bar ---------------------------------------------------
    address(push) {
        const tab = this.current();
        const target = new URL(location.href);
        if (tab && this.open) target.searchParams.set('ws', tab.url);
        else target.searchParams.delete('ws');
        const next = target.pathname + target.search + target.hash;
        if (next === location.pathname + location.search + location.hash) return;
        // Our own state only: with no Livewire snapshot in it, Livewire leaves Back and Forward
        // between these entries to us (popstate above) instead of restoring a page.
        const state = { hcWs: tab ? tab.url : null };
        if (push) history.pushState(state, '', next);
        else history.replaceState(state, '', next);
    },
    fromAddress(first) {
        const ws = new URL(location.href).searchParams.get('ws');
        if (ws && ws.startsWith('/') && ! ws.startsWith('//') && this.wants(ws)) {
            this.visit(ws, false);
        } else if (! first && this.open) {
            this.tabs = [];
            this.active = null;
            this.open = false;
        }
    },
});

const register = () => window.Alpine.data('hcWorkspace', workspace);

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
