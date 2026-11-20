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
            by: event.by ?? 0,
        });
    });

    // Who is online now: everyone on this presence channel (ids only).
    window.Echo.join('chat.online')
        .here((users) => {
            online.clear();
            users.forEach((u) => online.add(Number(u.id)));
            applyStatus();
        })
        .joining((user) => {
            online.add(Number(user.id));
            applyStatus();
        })
        .leaving((user) => {
            online.delete(Number(user.id));
            applyStatus();
        });
}

// ---- "New message from …" and the sound --------------------------------

let audio = null;

// Browsers only allow sound after the person has interacted with the page:
// the audio context is made on the first click or key press.
function unlockAudio() {
    if (audio || !window.AudioContext) {
        return;
    }

    try {
        audio = new window.AudioContext();
    } catch {
        audio = null;
    }
}

document.addEventListener('pointerdown', unlockAudio, { once: true, capture: true });
document.addEventListener('keydown', unlockAudio, { once: true, capture: true });

/** A short, soft two-note ping, made in the browser (no sound file). */
function playPing() {
    if (!audio) {
        return;
    }

    try {
        if (audio.state === 'suspended') {
            audio.resume();
        }

        const now = audio.currentTime;

        [[880, 0], [1320, 0.11]].forEach(([frequency, offset]) => {
            const osc = audio.createOscillator();
            const gain = audio.createGain();

            osc.type = 'sine';
            osc.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, now + offset);
            gain.gain.exponentialRampToValueAtTime(0.08, now + offset + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.18);
            osc.connect(gain).connect(audio.destination);
            osc.start(now + offset);
            osc.stop(now + offset + 0.2);
        });
    } catch {
        // No sound is better than an error.
    }
}

/** Whether this chat is open and actually in front of the person. */
function chatOnScreen(conversationId) {
    if (document.visibilityState !== 'visible' || !document.hasFocus()) {
        return false;
    }

    return [...document.querySelectorAll(`[data-chat-conversation="${conversationId}"][data-chat-open="1"]`)]
        .some((el) => el.getClientRects().length > 0);
}

let toastRegion = null;

function showChatToast(conversationId, title) {
    if (!toastRegion || !document.body.contains(toastRegion)) {
        toastRegion = document.createElement('div');
        toastRegion.setAttribute('role', 'status');
        toastRegion.setAttribute('aria-live', 'polite');
        toastRegion.dataset.testid = 'chat-toasts';
        toastRegion.className = 'pointer-events-none fixed end-6 top-20 z-[9600] flex w-72 flex-col gap-2';
        document.body.appendChild(toastRegion);
    }

    // One per chat: a newer message replaces the earlier toast.
    toastRegion.querySelector(`[data-chat-toast="${conversationId}"]`)?.remove();

    const toast = document.createElement('button');
    toast.type = 'button';
    toast.dataset.chatToast = String(conversationId);
    toast.className = 'pointer-events-auto flex w-full items-center gap-2.5 rounded-surface border border-line bg-card px-3 py-2.5 text-start text-small font-semibold text-ink shadow-e3 hover:bg-hover';
    toast.innerHTML = '<svg class="size-4 shrink-0 text-ink-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.5 7.2L4 21l1.8-5.2A8 8 0 1 1 21 12z"/></svg>';

    const text = document.createElement('span');
    text.className = 'min-w-0 flex-1 truncate';
    text.textContent = title; // text, never HTML
    toast.appendChild(text);

    toast.addEventListener('click', () => {
        window.Livewire?.dispatch('chat-open', { conversationId });
        toast.remove();
    });

    toastRegion.prepend(toast);
    [...toastRegion.children].slice(3).forEach((el) => el.remove());
    setTimeout(() => toast.remove(), 5000);
}

// The dock decides whether a message deserves a notice (not one's own, not a
// muted chat) and says whether the sound is on; here it is skipped while that
// chat is open on screen.
window.addEventListener('chat-notify', (event) => {
    const detail = event.detail ?? {};
    const conversationId = Number(detail.conversationId);

    if (!conversationId || chatOnScreen(conversationId)) {
        return;
    }

    showChatToast(conversationId, detail.title ?? '');

    if (detail.sound) {
        playPing();
    }
});

// ---- "typing…" and "online", applied straight to the page ---------------
//
// Plain state plus data- attributes, not Alpine stores: elements Livewire
// renders can be evaluated before a store is registered, and Alpine then
// never looks at them again. Here the state is (re)applied on every change
// and after every Livewire update (a MutationObserver on the page).
//
//   data-chat-online-dot="<userId>"        shown while that person is online
//   data-chat-when-online="<userId>"       shown while online
//   data-chat-when-offline="<userId>"      shown while not online
//   data-chat-when-typing="<chatId>"       shown while someone types there
//   data-chat-when-not-typing="<chatId>"   hidden while someone types there
//   data-chat-typing-name="<chatId>"       filled with who is typing (groups)

/** User ids online now (presence channel chat.online). */
const online = new Set();

/** chatId -> { name, until }: who is typing where; each lasts 4 seconds. */
const typing = new Map();

function isTyping(conversationId) {
    const entry = typing.get(conversationId);

    return Boolean(entry && entry.until > Date.now());
}

function setTyping(conversationId, name) {
    typing.set(conversationId, { name, until: Date.now() + 4000 });
    applyStatus();
    setTimeout(applyStatus, 4100);
}

function clearTyping(conversationId) {
    if (typing.delete(conversationId)) {
        applyStatus();
    }
}

function applyStatus() {
    document.querySelectorAll('[data-chat-online-dot],[data-chat-when-online]').forEach((el) => {
        el.hidden = !online.has(Number(el.dataset.chatOnlineDot ?? el.dataset.chatWhenOnline));
    });
    document.querySelectorAll('[data-chat-when-offline]').forEach((el) => {
        el.hidden = online.has(Number(el.dataset.chatWhenOffline));
    });
    document.querySelectorAll('[data-chat-when-typing]').forEach((el) => {
        el.hidden = !isTyping(Number(el.dataset.chatWhenTyping));
    });
    document.querySelectorAll('[data-chat-when-not-typing]').forEach((el) => {
        el.hidden = isTyping(Number(el.dataset.chatWhenNotTyping));
    });
    document.querySelectorAll('[data-chat-typing-name]').forEach((el) => {
        const entry = typing.get(Number(el.dataset.chatTypingName));
        el.textContent = (el.dataset.template ?? ':name').replace(':name', entry?.name ?? '');
    });
    // Every chat on the page listens for its own "typing…".
    document.querySelectorAll('[data-chat-conversation]').forEach((el) => {
        listenForTyping(Number(el.dataset.chatConversation) || 0);
    });
}

let statusFrame = null;

function scheduleStatus() {
    if (statusFrame) {
        return;
    }

    statusFrame = (window.requestAnimationFrame ?? setTimeout)(() => {
        statusFrame = null;
        applyStatus();
    });
}

// Livewire re-renders put back the server's markup: apply the state again.
if (typeof window !== 'undefined' && window.MutationObserver) {
    const startObserving = () => {
        new MutationObserver(scheduleStatus).observe(document.body, { childList: true, subtree: true });
        scheduleStatus();
    };

    if (document.body) {
        startObserving();
    } else {
        document.addEventListener('DOMContentLoaded', startObserving, { once: true });
    }
}

/**
 * One chat's "typing…" signal, by conversation id: listens on the
 * conversation's private channel. Whispers go browser to browser through
 * Reverb and are never stored.
 */
const typingSubscribed = new Set();

function listenForTyping(conversationId) {
    if (!window.Echo || !conversationId || typingSubscribed.has(conversationId)) {
        return;
    }

    typingSubscribed.add(conversationId);
    window.Echo.private(`chat.conversation.${conversationId}`).listenForWhisper('typing', (event) => {
        setTyping(conversationId, event.name ?? '');
    });
}

// A message from them ends their "typing…" at once.
window.addEventListener('chat-updated', (event) => {
    const detail = event.detail ?? {};

    if (detail.kind === 'message' && detail.conversationId) {
        clearTyping(Number(detail.conversationId));
    }
});

// While typing: at most one whisper every 2.5 seconds per chat. The chat id
// is read from the composer's own wrapper at the moment of typing (Livewire
// may have patched the element to another chat since the page loaded).
const lastWhisper = new Map();

document.addEventListener('input', (event) => {
    const composer = event.target instanceof Element ? event.target.closest('[data-chat-composer]') : null;

    if (!composer || !window.Echo) {
        return;
    }

    const id = Number(composer.closest('[data-chat-conversation]')?.dataset.chatConversation) || 0;

    if (!id || Date.now() - (lastWhisper.get(id) ?? 0) < 2500) {
        return;
    }

    lastWhisper.set(id, Date.now());
    window.Echo.private(`chat.conversation.${id}`).whisper('typing', { name: composer.dataset.chatComposer || '' });
});

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
        // Shown or hidden by hand rather than with x-show: the dock is a
        // persisted element, and its x-show stopped reacting after
        // wire:navigate, leaving it hidden on the page one came back to.
        const root = this.$root;
        this.checkPage = () => {
            this.onPage = !!document.querySelector('[data-chat-page]');
            root.style.display = this.onPage ? 'none' : '';
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

    maximize(url) {
        openFullPage(url);
    },
});

/**
 * The full chats page, remembering where to come back to. A full load, not
 * wire:navigate: on the dashboard the workspace (dashboard/workspace.js)
 * would otherwise open it in its panel over the dashboard.
 */
function openFullPage(url) {
    try {
        window.sessionStorage.setItem('hc-chat-return', window.location.href);
    } catch {
        // Storage blocked: "back" goes to the dashboard instead.
    }

    window.location.assign(url);
}

/** Back from the full page to wherever it was opened from. */
function leaveFullPage(fallbackUrl) {
    let back = fallbackUrl;

    try {
        back = window.sessionStorage.getItem('hc-chat-return') || fallbackUrl;
        window.sessionStorage.removeItem('hc-chat-return');
    } catch {
        // Storage blocked: go to the fallback.
    }

    window.Livewire.navigate(back);
}

window.hcChatLeaveFullPage = leaveFullPage;

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

            this.markSeen();
        });

        if (this.$refs.scroller) {
            this.observer.observe(this.$refs.scroller, { childList: true, subtree: true });
        }

        this.poll = setInterval(() => {
            if (!live() && document.visibilityState === 'visible') {
                this.$wire.$refresh();
            }
        }, 3000);

        // Coming back to this browser tab reads what arrived meanwhile.
        this.onVisible = () => this.markSeen();
        document.addEventListener('visibilitychange', this.onVisible);
        window.addEventListener('focus', this.onVisible);

        this.markSeen();
        this.$nextTick(() => this.$refs.composer?.focus({ preventScroll: true }));
    },

    destroy() {
        this.observer?.disconnect();
        clearInterval(this.poll);
        clearTimeout(this.seenTimer);
        document.removeEventListener('visibilitychange', this.onVisible);
        window.removeEventListener('focus', this.onVisible);
    },

    // Read only what is actually in front of the person: the chat is open
    // here and this browser tab is visible. Batched, so a burst of updates
    // makes one call.
    markSeen() {
        if (document.visibilityState !== 'visible') {
            return;
        }

        clearTimeout(this.seenTimer);
        this.seenTimer = setTimeout(() => this.$wire.seen(), 400);
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

    // Maximise: the full chat page on this chat (the dock brings its tabs
    // back from this browser's memory after the full load).
    maximize(url) {
        openFullPage(url);
    },

    // From the full page: the chat goes back on the dock as an open tab, and
    // the person returns to the page they maximised from. The dock is still
    // mounted (hidden) on the full page, so it opens the tab and keeps it
    // across the navigation back.
    backToDock(conversationId, fallbackUrl) {
        window.Livewire.dispatch('chat-open', { conversationId });
        leaveFullPage(fallbackUrl);
    },

    // Desktop: Enter sends, Shift+Enter is a new line, never while an IME is
    // composing. Phone width: Enter is a new line and the send button sends
    // (a phone keyboard has no Shift+Enter; the button shows only there).
    keydown(event) {
        if (window.matchMedia('(max-width: 767px)').matches) {
            return;
        }

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
