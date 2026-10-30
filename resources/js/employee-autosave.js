/**
 * Employee draft autosave: phase 2 of saving (DEC-04, B5D-Q10,
 * docs/ux/employee-lifecycle.md §5.6, docs/ux/b5-implementation.md §8).
 *
 * Drives the status line in partials/onboarding-status.blade.php, on the
 * create page and on a draft's edit page:
 *
 *   - a few seconds after the last change to the record fields (infoForm), or
 *     at once when the person switches section, it asks the component's
 *     autosaveDraft() to save the draft on the server;
 *   - it asks only once the draft minimum is filled in (8 names, governorate,
 *     national ID, username). Whether those are valid (in scope, unique, 11
 *     digits) is decided on the server, which answers "incomplete" without
 *     showing an error: the person is still typing;
 *   - after a save it marks the B2 leave guard clean for what was sent, so
 *     typing that happened meanwhile still counts as unsaved.
 *
 * Nothing is ever written to localStorage or sessionStorage (B5D-Q10): the
 * draft lives in the database or nowhere.
 */

// Wait this long after the last change before saving.
const DELAY = 2500;

// Set by the server, never typed: not a change worth saving.
const SERVER_KEYS = ['employee', 'id'];

// Stored by an explicit save only (PersonalInfoForm::saveDraft(withPhoto: false)),
// so a picked photo stays "unsaved" for the leave guard until then.
const EXPLICIT_ONLY = ['photo'];

const blank = (value) => value === null || value === undefined || String(value).trim() === '';

/** The part of the form the person typed, as a comparable string. */
function typedState(form) {
    const copy = { ...(form ?? {}) };

    for (const key of [...SERVER_KEYS, ...EXPLICIT_ONLY]) delete copy[key];

    return JSON.stringify(copy);
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('employeeDraftAutosave', (config) => ({
        message: '',
        draftUrl: null,
        timer: null,
        inFlight: false,
        again: false,
        stopped: false,
        // What was last sent (or loaded): only a difference from it is saved.
        last: null,

        init() {
            // The server-rendered line ("Saved … by …" or "Not saved yet …").
            this.message = this.$el.dataset.savedLine ?? '';
            this.draftUrl = this.$el.dataset.draftUrl || null;
            this.last = typedState(this.read());

            // An explicit save (Save draft, Complete record) saved everything on
            // screen: take the server's fresh line, and skip a redundant autosave.
            this.onExplicitSave = (event) => {
                if (event.detail?.form !== 'infoForm') return;

                setTimeout(() => {
                    clearTimeout(this.timer);
                    this.timer = null;
                    this.last = typedState(this.read());
                    this.message = this.$el.dataset.savedLine ?? this.message;
                });
            };
            window.addEventListener('form-saved', this.onExplicitSave);

            this.$watch(
                () => typedState(this.read()),
                (state) => {
                    if (state !== this.last) this.schedule();
                },
            );

            // A section change saves now instead of after the delay.
            this.$watch(
                () => this.$wire.activeTab,
                () => this.flush(),
            );

            this.onHide = () => {
                if (document.visibilityState === 'hidden') this.flush();
            };
            document.addEventListener('visibilitychange', this.onHide);
        },

        destroy() {
            clearTimeout(this.timer);
            this.stopped = true;
            document.removeEventListener('visibilitychange', this.onHide);
            window.removeEventListener('form-saved', this.onExplicitSave);
        },

        read() {
            return this.$wire.$get('infoForm', true);
        },

        minimumFilled() {
            const form = this.read() ?? {};

            return config.required.every((prop) => !blank(form[prop]));
        },

        schedule() {
            clearTimeout(this.timer);
            this.timer = null;

            if (this.stopped) return;

            if (!this.minimumFilled()) {
                this.message = config.messages.notYet;

                return;
            }

            this.timer = setTimeout(() => this.save(), DELAY);
        },

        flush() {
            if (this.timer || typedState(this.read()) !== this.last) this.save();
        },

        async save() {
            clearTimeout(this.timer);
            this.timer = null;

            if (this.stopped) return;

            // One at a time; whatever changed meanwhile is saved right after.
            if (this.inFlight) {
                this.again = true;

                return;
            }

            const form = this.read();
            const sentState = typedState(form);

            if (sentState === this.last) return;

            if (!this.minimumFilled()) {
                this.message = config.messages.notYet;

                return;
            }

            const sent = JSON.parse(JSON.stringify(form ?? {}));
            const before = this.message;
            this.inFlight = true;
            this.message = config.messages.saving;

            let result;
            try {
                result = await this.$wire.autosaveDraft();
            } catch (error) {
                // No status: the request was cancelled or superseded (an
                // explicit save, a navigation). Anything else failed.
                result = { status: error?.status ? 'failed' : 'cancelled' };
            } finally {
                this.inFlight = false;
            }

            switch (result?.status) {
                case 'saved':
                    this.last = sentState;
                    this.message = result.message;
                    if (result.url) this.draftUrl = result.url;
                    this.markClean(sent, sentState);
                    break;
                case 'incomplete':
                    this.last = sentState;
                    this.message = config.messages.notYet;
                    break;
                case 'complete':
                    // Saved explicitly or completed meanwhile: nothing more to autosave.
                    this.stopped = true;
                    this.message = before;
                    break;
                case 'failed':
                    // Retried on the next change; Save draft still works.
                    this.message = config.messages.failed;
                    break;
                default:
                    // busy (an explicit save holds the record) or cancelled: try again later.
                    this.message = before;
                    this.again = true;
            }

            if (this.again) {
                this.again = false;
                this.schedule();
            } else if (!this.stopped && result?.status !== 'failed' && typedState(this.read()) !== this.last) {
                this.schedule();
            }
        },

        /** The leave guard (B2): what was sent is saved; later typing is not, and neither is a photo. */
        markClean(sent, sentState) {
            window.hcUnsaved?.markSaved('infoForm', (baseline, current) => {
                const saved = typedState(current) === sentState ? { ...current } : { ...sent };

                for (const key of SERVER_KEYS) {
                    if (current && key in current) saved[key] = current[key];
                }
                for (const key of EXPLICIT_ONLY) {
                    if (baseline && key in baseline) saved[key] = baseline[key];
                }

                return saved;
            });
        },
    }));
});
