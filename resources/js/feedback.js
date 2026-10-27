/**
 * One way to report an outcome and one way to ask before acting.
 *
 *   $store.toasts   — rendered by <x-toasts/>, fed by the `toast` window event
 *                     (sent by WithFeedback::toast(), or `$dispatch('toast', {...})`)
 *   $store.confirm  — rendered by <x-confirm-dialog/>, fed by the `confirm-dialog`
 *                     window event (WithFeedback::confirm()) or by `$confirm()`
 *   errorSummary    — the form-level error list, which only lists errors whose
 *                     inline message is not on screen (see errors-validation-list)
 *
 * Both components are mounted once in the app layout, and Alpine stores live
 * on window, so a toast raised just before a wire:navigate survives the swap.
 */

const DURATION = { success: 5000, info: 5000, error: 8000 };
const UNDO_DURATION = 8000; // long enough to read the message and reach the button
const MAX_VISIBLE = 4;

const FOCUSABLE = 'input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"]), [contenteditable=true]';

const isVisible = (el) => el.getClientRects().length > 0;

/**
 * Rendered *and* inside the visible area: within the window and within every
 * scrolling container around it (a dialog's body on a phone). An element far
 * down a long form is rendered but not seen (G-B-3).
 */
function isInView(el) {
    if (!isVisible(el)) return false;

    const rect = el.getBoundingClientRect();
    const overlaps = (box) => rect.bottom > box.top && rect.top < box.bottom && rect.right > box.left && rect.left < box.right;

    if (!overlaps({ top: 0, left: 0, bottom: window.innerHeight, right: window.innerWidth })) return false;

    for (let box = el.parentElement; box && box !== document.body; box = box.parentElement) {
        if (getComputedStyle(box).overflowY !== 'visible' && !overlaps(box.getBoundingClientRect())) return false;
    }

    return true;
}

// select2 hides the real <select> and takes focus on its own box.
function focusTarget(el) {
    if (el?.classList?.contains('select2-hidden-accessible')) {
        return el.nextElementSibling?.querySelector('.select2-selection') ?? null;
    }

    return el && isVisible(el) ? el : null;
}

/**
 * The field behind an error key: the control bound to it (wire:model, any
 * modifier) or named after it, else the first control next to its inline
 * message (fields bound through Alpine, such as the contract composer's).
 */
function locateField(key, message, summary) {
    const bound = [...document.querySelectorAll('input, select, textarea')].filter((el) =>
        el.getAttribute('name') === key
        || [...el.attributes].some((attr) => attr.name.startsWith('wire:model') && attr.value === key),
    );

    for (const el of bound) {
        const target = focusTarget(el);

        if (target) return target;
    }

    if (!message) return null;

    const inline = [...document.querySelectorAll('[data-inline-error]')].find(
        (el) => !summary.contains(el) && isVisible(el) && el.textContent.trim() === message,
    );

    for (let box = inline?.parentElement; box && box !== document.body; box = box.parentElement) {
        const target = [...box.querySelectorAll(FOCUSABLE)].map(focusTarget).find(Boolean);

        if (target) return target;
    }

    return null;
}

/**
 * Take the user to a field: focus it if it is on screen, otherwise open its
 * tab or section first (`reveal`, a Livewire method called with the section,
 * and the `reveal-field` window event), then focus it once it renders.
 * Shared by the error summary and the onboarding checklist (B2, B5).
 */
async function revealField(key, { section = null, reveal = null, wire = null, message = '', summary = document.createElement('div') } = {}) {
    let field = locateField(key, message, summary);

    if (!field && section) {
        window.dispatchEvent(new CustomEvent('reveal-field', { detail: { key, section } }));

        if (reveal && wire) await wire.call(reveal, section);
    }

    // The tab or section renders on a later frame.
    for (let attempt = 0; !field && attempt < 20; attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 50));
        field = locateField(key, message, summary);
    }

    if (!field) return;

    field.scrollIntoView({ block: 'center' });
    field.focus({ preventScroll: true });
}

window.hcRevealField = revealField;

/*
 * When did the user last press, click or tap? Lets the code below react only
 * to requests the user caused (a Save), never to background polling.
 */
let lastInteraction = 0;
['pointerdown', 'keydown'].forEach((type) =>
    document.addEventListener(type, () => (lastInteraction = Date.now()), true),
);
const userJustActed = () => Date.now() - lastInteraction < 10000;

/*
 * G-B-2: a request that never reached the server (connection lost, server
 * down) used to fail silently: no message, the button simply came back.
 *
 * Livewire reports it as an action failure: fetch itself threw. A server
 * error is reported as an error instead and keeps Livewire's own dialog, and
 * an aborted request reports neither. One toast says so and offers Try again,
 * which sends the same actions again (the B3 retry: repeat the same request).
 * Entries stay on the page and Livewire sends them again with the call; a
 * repeat is safe (A3/B2 submission tokens; a leave decision already made is
 * reported as such, G-B-1).
 */
let unreachable = { toastId: null, actions: [] };
let failedTogether = [];

function reportUnreachable(action) {
    // Polling is not the user's doing, and pauses itself while offline.
    if (action.metadata?.type === 'poll' || !userJustActed()) return;

    // Every action in the failed request lands here in the same tick.
    if (failedTogether.push(action) > 1) return;

    queueMicrotask(() => {
        const actions = failedTogether;

        failedTogether = [];
        showUnreachable(actions);
    });
}

function showUnreachable(actions) {
    const store = window.Alpine?.store('toasts');
    const text = window.hcFeedbackText;

    if (!store || !text?.networkError) return;

    // Still on screen: one toast, whose Try again covers these as well.
    if (store.find(unreachable.toastId)) {
        unreachable.actions = withoutRepeats([...unreachable.actions, ...actions]);

        return;
    }

    unreachable.actions = withoutRepeats(actions);
    unreachable.toastId = store.push({
        type: 'error',
        message: text.networkError,
        action: { label: text.tryAgain, run: tryAgain },
    });
}

// Two failed clicks on the same Save are one thing to try again.
function withoutRepeats(actions) {
    return [...new Map(actions.map((action) => [action.fingerprint, action])).values()];
}

function tryAgain() {
    const actions = unreachable.actions;

    unreachable = { toastId: null, actions: [] };

    // The toast that held focus is gone: give it back to the control used.
    const origin = actions[0]?.origin;
    const control = origin?.targetEl ?? origin?.el;

    if (control?.isConnected && control.matches(FOCUSABLE) && isVisible(control)) {
        control.focus({ preventScroll: true });
    }

    actions.forEach((action) => {
        // A component that has left the page (dialog closed, page changed)
        // has nothing to repeat.
        if (!window.Livewire.find(action.component.id)) return;

        window.Livewire.fireAction(action.component, action.name, action.params, action.metadata);
    });
}

document.addEventListener('livewire:init', () => {
    window.Livewire.interceptAction(({ action, onFailure }) => {
        onFailure(() => reportUnreachable(action));
    });
});

// Calls a method on the Livewire component that raised the toast or the
// confirmation. A null payload means "call with no argument".
function callBack({ component, method, payload }) {
    const wire = window.Livewire?.find(component);

    if (!wire) return;

    payload === null || payload === undefined ? wire.$call(method) : wire.$call(method, payload);
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.store('toasts', {
        items: [],
        nextId: 1,

        /**
         * `undo` comes from the server (WithFeedback::toast) and calls a
         * component back; `action` ({label, run}) is for toasts raised in the
         * browser, such as Try again (G-B-2). Returns the toast's id.
         */
        push({ type = 'info', message = '', undo = null, action = null, duration = null } = {}) {
            if (!message) return null;

            const toast = {
                id: this.nextId++,
                type,
                message,
                undo,
                action,
                remaining: duration ?? (undo || action ? UNDO_DURATION : DURATION[type] ?? DURATION.info),
                timer: null,
                startedAt: 0,
            };

            this.items.push(toast);
            this.items.splice(0, Math.max(0, this.items.length - MAX_VISIBLE)).forEach((old) => clearTimeout(old.timer));
            this.resume(toast.id);

            return toast.id;
        },

        find(id) {
            return this.items.find((toast) => toast.id === id);
        },

        dismiss(id) {
            const toast = this.find(id);

            if (toast) clearTimeout(toast.timer);

            this.items = this.items.filter((item) => item.id !== id);
        },

        // Hovering or focusing a toast holds it open; leaving resumes the countdown
        // from where it stopped rather than restarting it.
        pause(id) {
            const toast = this.find(id);

            if (!toast || toast.timer === null) return;

            clearTimeout(toast.timer);
            toast.timer = null;
            toast.remaining -= Date.now() - toast.startedAt;
        },

        resume(id) {
            const toast = this.find(id);

            if (!toast || toast.timer !== null) return;

            toast.startedAt = Date.now();
            toast.timer = setTimeout(() => this.dismiss(id), Math.max(toast.remaining, 1000));
        },

        undo(id) {
            const toast = this.find(id);

            if (!toast?.undo) return;

            this.dismiss(id);
            callBack(toast.undo);
        },

        act(id) {
            const toast = this.find(id);

            if (!toast?.action) return;

            this.dismiss(id);
            toast.action.run();
        },
    });

    Alpine.store('confirm', {
        open: false,
        title: '',
        message: '',
        html: null,
        confirmLabel: '',
        cancelLabel: '',
        danger: true,
        // Translated delete wording, set by <x-confirm-dialog/> from the server.
        defaults: {},
        resolver: null,

        ask(options = {}) {
            // A second request replaces an unanswered first one; the first is a "no".
            this.settle(false);

            const settings = { ...this.defaults, ...options };

            this.title = settings.title ?? '';
            this.message = settings.html ? '' : settings.message ?? '';
            this.html = settings.html ?? null;
            this.confirmLabel = settings.confirmLabel ?? '';
            // WithFeedback sends null when the caller did not rename it.
            this.cancelLabel = settings.cancelLabel || this.defaults.cancelLabel || '';
            this.danger = settings.danger ?? true;

            return new Promise((resolve) => {
                this.resolver = resolve;
                this.open = true;
            });
        },

        settle(confirmed) {
            const resolve = this.resolver;

            this.resolver = null;
            this.open = false;

            if (resolve) resolve(confirmed);
        },
    });

    // In markup: x-on:click="if (await $confirm()) $wire.deleteRole()"
    // Called with no options it asks the standard delete question.
    Alpine.magic('confirm', () => (options) => Alpine.store('confirm').ask(options));

    // An event cannot carry a function, so `action` is browser-only.
    window.addEventListener('toast', (event) => {
        const { action, ...toast } = event.detail ?? {};

        Alpine.store('toasts').push(toast);
    });

    window.addEventListener('confirm-dialog', async (event) => {
        const { componentId, method, payload, ...options } = event.detail ?? {};

        if (await Alpine.store('confirm').ask(options)) {
            callBack({ component: componentId, method, payload });
        }
    });

    /**
     * Only the active tab of a form is rendered, so an error on another tab has
     * no inline message to show it. The summary lists exactly those errors: one
     * whose inline message is on screen is left out.
     *
     * An inline message is matched by key (data-error-for, set by
     * <x-forms.input-error>) or, for the many <x-error-span>s that only
     * receive the text, by that text.
     *
     * Each entry links to its field (B2): go() opens the tab or section that
     * holds it (`reveal`, a Livewire method such as setActiveTab, and the
     * `reveal-field` window event) and then focuses it.
     */
    Alpine.data('errorSummary', (reveal = null) => ({
        reveal,
        keys: [],
        covered: [],
        ready: false,
        stopListening: null,

        init() {
            this.sync();
            requestAnimationFrame(() => this.sync());

            // G-B-3: this summary appears with the first failed save. Take the
            // user to the first error, which may be off screen on a phone.
            if (userJustActed()) this.afterPaint(() => this.focusFirstError());

            // Switching tab re-renders the form without clearing the errors, so
            // which messages are on screen changes after every round trip.
            this.stopListening = window.Livewire?.hook('commit', ({ component, commit, succeed }) => {
                // A save (a method call on this summary's component), not a field update.
                const isSave = component?.id === this.$wire?.$id && (commit?.calls?.length ?? 0) > 0;

                succeed(() => queueMicrotask(() => {
                    this.sync();

                    if (isSave && this.keys.length) this.afterPaint(() => this.focusFirstError());
                }));
            });

            // Collapsing a section or switching an Alpine-only tab hides
            // messages without a round trip; check again after the next paint.
            this.resync = () => requestAnimationFrame(() => requestAnimationFrame(() => this.sync()));
            document.addEventListener('click', this.resync, true);
            document.addEventListener('keyup', this.resync, true);
        },

        destroy() {
            this.stopListening?.();
            document.removeEventListener('click', this.resync, true);
            document.removeEventListener('keyup', this.resync, true);
        },

        sync() {
            // Only a message the user can see now counts as shown. Re-checked
            // after a save, a click or a key, not on scroll, so the list does
            // not jump while the user scrolls to a field.
            const inline = [...document.querySelectorAll('[data-inline-error]')].filter(
                (el) => isInView(el) && !this.$el.contains(el),
            );
            const items = [...this.$el.querySelectorAll('[data-error-key]')];

            this.keys = items.map((item) => item.dataset.errorKey);
            this.covered = items
                .filter((item) =>
                    inline.some((el) =>
                        el.dataset.errorFor
                            ? el.dataset.errorFor === item.dataset.errorKey
                            : el.textContent.trim().includes(item.textContent.trim()),
                    ),
                )
                .map((item) => item.dataset.errorKey);
            this.ready = true;
        },

        isCovered(key) {
            return this.covered.includes(key);
        },

        afterPaint(callback) {
            requestAnimationFrame(() => requestAnimationFrame(callback));
        },

        /**
         * The first error's field, scrolled to the middle and focused; if that
         * field is on another tab or in a closed section, the summary itself,
         * whose entries lead there. Nothing moves when there is no error.
         */
        focusFirstError() {
            const summary = this.$root;
            const first = summary.querySelector('[data-error-key]');

            if (!first) return;

            const field = locateField(first.dataset.errorKey, first.textContent.trim(), summary);

            if (field) {
                field.scrollIntoView({ block: 'center' });
                field.focus({ preventScroll: true });

                return;
            }

            const entry = [...summary.querySelectorAll('[data-error-key] button')].find(isVisible);

            if (entry) {
                summary.scrollIntoView({ block: 'center' });
                entry.focus({ preventScroll: true });
            }
        },

        go(key, section = null) {
            // $root, not $el: called from an entry's click, $el is that button.
            const summary = this.$root;
            const message = summary.querySelector(`[data-error-key="${CSS.escape(key)}"]`)?.textContent.trim() ?? '';

            return revealField(key, { section, reveal: this.reveal, wire: this.$wire, message, summary });
        },

        get hasUncovered() {
            return this.ready && this.keys.some((key) => !this.covered.includes(key));
        },
    }));
});
