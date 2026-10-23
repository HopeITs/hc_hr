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

        push({ type = 'info', message = '', undo = null, duration = null } = {}) {
            if (!message) return;

            const toast = {
                id: this.nextId++,
                type,
                message,
                undo,
                remaining: duration ?? (undo ? UNDO_DURATION : DURATION[type] ?? DURATION.info),
                timer: null,
                startedAt: 0,
            };

            this.items.push(toast);
            this.items.splice(0, Math.max(0, this.items.length - MAX_VISIBLE)).forEach((old) => clearTimeout(old.timer));
            this.resume(toast.id);
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

    window.addEventListener('toast', (event) => Alpine.store('toasts').push(event.detail ?? {}));

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

            // Switching tab re-renders the form without clearing the errors, so
            // which messages are on screen changes after every round trip.
            this.stopListening = window.Livewire?.hook('commit', ({ succeed }) => {
                succeed(() => queueMicrotask(() => this.sync()));
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
            const visible = (el) => el.getClientRects().length > 0;
            const inline = [...document.querySelectorAll('[data-inline-error]')].filter(
                (el) => visible(el) && !this.$el.contains(el),
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

        async go(key, section = null) {
            // $root, not $el: called from an entry's click, $el is that button.
            const summary = this.$root;
            const message = summary.querySelector(`[data-error-key="${CSS.escape(key)}"]`)?.textContent.trim() ?? '';
            let field = locateField(key, message, summary);

            if (!field && section) {
                window.dispatchEvent(new CustomEvent('reveal-field', { detail: { key, section } }));

                if (this.reveal) await this.$wire.call(this.reveal, section);
            }

            // The tab or section renders on a later frame.
            for (let attempt = 0; !field && attempt < 20; attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
                field = locateField(key, message, summary);
            }

            if (!field) return;

            field.scrollIntoView({ block: 'center', behavior: 'smooth' });
            field.focus({ preventScroll: true });
        },

        get hasUncovered() {
            return this.ready && this.keys.some((key) => !this.covered.includes(key));
        },
    }));
});
