/**
 * The chat dock's browser side (App\Livewire\Chat\Dock and Window).
 *
 * - Realtime: listens on the person's own channel (chat.user.{id}). A signal
 *   carries ids only; it tells the Livewire components which chat changed,
 *   and they fetch the change from the site.
 * - Fallback: while the realtime connection is down, open chats ask the site
 *   every 3 seconds and the dock every 15, so nothing is missed.
 * - Which tabs are on the bar is remembered in this browser only, so a
 *   refresh brings them back. The server re-checks every id it is handed.
 */

const live = () => window.Echo?.connector?.pusher?.connection?.state === 'connected';

window.hcChatLive = live;

let subscribed = false;

function subscribe() {
    if (subscribed || !window.Echo || !document.querySelector('meta[name="chat-enabled"]')) {
        return;
    }

    const userId = document.querySelector('meta[name="user-id"]')?.content;

    if (!userId) {
        return;
    }

    subscribed = true;

    window.Echo.private(`chat.user.${userId}`).listen('.chat.updated', (event) => {
        window.Livewire?.dispatch('chat-updated', {
            conversationId: event.conversation_id,
            kind: event.kind,
        });
    });
}

function readTabs(key) {
    try {
        const saved = JSON.parse(window.localStorage.getItem(key) || '[]');

        return Array.isArray(saved) ? saved : [];
    } catch {
        return [];
    }
}

function writeTabs(key, tabs) {
    try {
        window.localStorage.setItem(key, JSON.stringify(tabs));
    } catch {
        // Private mode or storage blocked: tabs just won't survive a refresh.
    }
}

/**
 * How much of the page footer is on screen, so the dock (and the assistant
 * button above it) sits on the footer's top line instead of over it. Zero
 * when the footer is scrolled out of view, and on phones (no dock bar there).
 */
function footerLift() {
    if (window.innerWidth < 768) {
        return 0;
    }

    const footer = document.querySelector('.app-shell > footer');

    if (!footer) {
        return 0;
    }

    return Math.max(0, Math.round(window.innerHeight - footer.getBoundingClientRect().top));
}

let liftFrame = null;

function updateLift() {
    if (liftFrame) {
        return;
    }

    liftFrame = window.requestAnimationFrame(() => {
        liftFrame = null;
        document.documentElement.style.setProperty('--chat-dock-lift', `${footerLift()}px`);
    });
}

const chatDock = (key) => ({
    // On the full chat page the dock steps aside: the page is the chat.
    onPage: false,

    init() {
        subscribe();

        updateLift();
        window.addEventListener('scroll', updateLift, { passive: true });
        window.addEventListener('resize', updateLift, { passive: true });
        this.checkPage = () => {
            this.onPage = !!document.querySelector('[data-chat-page]');
            updateLift();
        };
        this.checkPage();
        document.addEventListener('livewire:navigated', this.checkPage);

        const saved = readTabs(key);

        if (saved.length) {
            this.$wire.restore(saved);
        }

        this.poll = setInterval(() => {
            if (!live() && document.visibilityState === 'visible') {
                this.$wire.$refresh();
            }
        }, 15000);
    },

    destroy() {
        clearInterval(this.poll);
        window.removeEventListener('scroll', updateLift);
        window.removeEventListener('resize', updateLift);
        document.removeEventListener('livewire:navigated', this.checkPage);
    },

    save(tabs) {
        writeTabs(key, tabs);
    },
});

const chatWindow = () => ({
    nearBottom: true,

    init() {
        this.$nextTick(() => this.toBottom());

        // New messages keep the view at the bottom, unless the person has
        // scrolled up to read older ones.
        this.observer = new MutationObserver(() => {
            if (this.nearBottom) {
                this.toBottom();
            }
        });

        if (this.$refs.scroller) {
            this.observer.observe(this.$refs.scroller, { childList: true, subtree: true });
        }

        this.poll = setInterval(() => {
            if (!live() && document.visibilityState === 'visible') {
                this.$wire.$refresh();
            }
        }, 3000);

        this.$nextTick(() => this.$refs.composer?.focus({ preventScroll: true }));
    },

    destroy() {
        this.observer?.disconnect();
        clearInterval(this.poll);
    },

    onScroll() {
        const s = this.$refs.scroller;

        this.nearBottom = s.scrollHeight - s.scrollTop - s.clientHeight < 60;
    },

    toBottom() {
        const s = this.$refs.scroller;

        if (s) {
            s.scrollTop = s.scrollHeight;
        }
    },

    // Keep the message that was at the top in place while older ones load above.
    older() {
        const s = this.$refs.scroller;
        const before = s.scrollHeight - s.scrollTop;

        this.nearBottom = false;
        this.$wire.loadOlder().then(() => {
            this.$nextTick(() => {
                s.scrollTop = s.scrollHeight - before;
            });
        });
    },

    // Maximise: the full chat page, remembering where to come back to.
    maximize(url) {
        try {
            window.sessionStorage.setItem('hc-chat-return', window.location.href);
        } catch {
            // Storage blocked: "back" goes to the dashboard instead.
        }

        // A full load, not wire:navigate: on the dashboard, the workspace
        // (dashboard/workspace.js) would otherwise open the chat page in its
        // panel over the dashboard instead of the whole window. The dock
        // brings its tabs back from this browser's memory.
        window.location.assign(url);
    },

    // From the full page: the chat goes back on the dock as an open tab, and
    // the person returns to the page they maximised from.
    backToDock(conversationId, fallbackUrl) {
        let back = fallbackUrl;

        try {
            back = window.sessionStorage.getItem('hc-chat-return') || fallbackUrl;
            window.sessionStorage.removeItem('hc-chat-return');
        } catch {
            // Storage blocked: go to the fallback.
        }

        // The dock is still mounted (hidden) on this page, so it opens the tab
        // and keeps it across the navigation back.
        window.Livewire.dispatch('chat-open', { conversationId });
        window.Livewire.navigate(back);
    },

    // Enter sends; Shift+Enter is a new line; never while an IME is composing.
    keydown(event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            this.submit();
        }
    },

    submit() {
        if (this.$refs.composer && this.$refs.composer.value.trim() === '') {
            return;
        }

        this.nearBottom = true;
        this.$wire.send();
    },
});

if (typeof window !== 'undefined') {
    const register = () => {
        window.Alpine.data('chatDock', chatDock);
        window.Alpine.data('chatWindow', chatWindow);
    };

    // On a full load Alpine is there but not started; after wire:navigate it is running.
    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
}
