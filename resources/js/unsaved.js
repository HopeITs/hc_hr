/**
 * Leaving a page with unsaved changes (B2).
 *
 * One registry of "is there unsaved work here?" checks, consulted before the
 * page goes away:
 *
 *   - wire:navigate links and Livewire redirects (`livewire:navigate`): one
 *     confirmation, in the A5-M wording ("Discard your changes?" / Keep
 *     editing / Discard changes), through the shared B0 confirm dialog;
 *   - reload, closing the tab, plain links and form posts (`beforeunload`):
 *     the browser's own prompt, whose wording the browser decides;
 *   - the Back button while an editable dialog is open: the dialog's own close
 *     route (A5-M's requestClose), so the dialog decides and at most one
 *     question is asked.
 *
 * What registers:
 *   - <x-forms.dirty-gate> for each Livewire form it watches. The baseline is
 *     kept here, per component and form, so it survives the tab re-rendering:
 *     switching tabs neither forgets nor rebaselines unsaved work.
 *   - the contract composer (resources/js/contract-composer.js);
 *   - <x-modal> dialogs that pass close-action (A5-M). Whether their entries
 *     changed is decided on the server (GuardsModalClose::isDirty), which the
 *     synchronous `beforeunload` cannot ask, so for that event only a dialog
 *     counts as changed once the user typed or picked something in it.
 *
 * A form stays "unsaved" after a failed save; only a confirmed successful save
 * (the `form-saved` event) or an explicit discard clears it. Nothing is kept
 * in browser storage.
 */

const entries = new Map();

let bypassNextNavigation = false;

/*
 * Back/Forward cannot be cancelled, only undone. Livewire has already moved
 * to the other history entry (and its own record of the current page with
 * it) when it asks whether it may swap the page, so a refused step is undone
 * by stepping the browser back the other way, which Livewire follows too.
 * The order of entries is kept here because Livewire's keys are random.
 */
const positions = new Map();
let lastPosition = 0;
let currentKey = null;
// 'pending' while the refused step's own popstate finishes, then 'armed'
// until the undo's popstate arrives.
let undoingStep = null;

function entryKey() {
    return window.history.state?.alpine?.snapshotIdx ?? null;
}

function rememberCurrentEntry() {
    currentKey = entryKey();

    if (currentKey && !positions.has(currentKey)) positions.set(currentKey, ++lastPosition);
}

/** Returns the step the user took (-1 Back, 1 Forward) after undoing it. */
function undoHistoryStep() {
    // An entry never recorded is one older than every recorded page: Back.
    const target = positions.get(entryKey()) ?? 0;
    const current = positions.get(currentKey) ?? lastPosition + 1;
    const step = target > current ? 1 : -1;

    undoingStep = 'pending';
    window.history.go(-step);

    return step;
}

// Livewire's popstate listener was added first, so it has already run here.
window.addEventListener('popstate', () => {
    if (undoingStep === 'pending') {
        undoingStep = 'armed';
    } else if (undoingStep === 'armed') {
        // Livewire did not ask to navigate for the undo: nothing to swallow.
        setTimeout(() => (undoingStep = null));
    }
});

function alive(entry) {
    return !entry.wireId || Boolean(window.Livewire?.find(entry.wireId));
}

function prune() {
    for (const [id, entry] of entries) {
        if (!alive(entry)) entries.delete(id);
    }
}

function snapshot(wireId, path) {
    return JSON.stringify(window.Livewire?.find(wireId)?.$get(path) ?? null);
}

const unsaved = {
    // Translated by <x-confirm-dialog/>, the same keys A5-M uses.
    labels: {},

    register(id, entry) {
        entries.set(id, entry);
    },

    unregister(id) {
        entries.delete(id);
    },

    /**
     * A Livewire form property, compared with what it held when it was first
     * shown (or last saved). Registering again keeps the original baseline.
     */
    watchForm(wireId, path) {
        const id = `form:${wireId}:${path}`;

        if (!entries.has(id)) {
            entries.set(id, {
                wireId,
                path,
                baseline: snapshot(wireId, path),
                dirty() {
                    return snapshot(this.wireId, this.path) !== this.baseline;
                },
            });
        }

        return entries.get(id);
    },

    /** After a confirmed save: what is on screen now is the saved state. */
    rebaseline(path) {
        for (const entry of entries.values()) {
            if (entry.path === path && alive(entry)) entry.baseline = snapshot(entry.wireId, path);
        }
    },

    pageIsDirty() {
        prune();

        return [...entries.values()].some((entry) => entry.kind !== 'dialog' && entry.dirty());
    },

    openDialog() {
        return [...entries.values()].find((entry) => entry.kind === 'dialog' && entry.isOpen());
    },

    /** For beforeunload only; see the note on dialogs above. */
    anythingUnsaved() {
        return this.pageIsDirty() || Boolean(this.openDialog()?.touched());
    },

    ask() {
        return window.Alpine.store('confirm').ask({
            title: this.labels.title,
            message: this.labels.message,
            confirmLabel: this.labels.discard,
            cancelLabel: this.labels.keepEditing,
            danger: false,
        });
    },
};

window.hcUnsaved = unsaved;

document.addEventListener('alpine:init', () => {
    window.Alpine.magic('unsaved', () => unsaved);
});

// Dispatched by a Livewire component after a save that really succeeded.
window.addEventListener('form-saved', (event) => {
    const path = event.detail?.form;

    // After Livewire has applied the response, so the snapshot is the saved state.
    if (path) setTimeout(() => unsaved.rebaseline(path));
});

document.addEventListener('livewire:navigated', () => {
    rememberCurrentEntry();
    prune();
});

document.addEventListener('livewire:navigate', (event) => {
    const { url, history } = event.detail ?? {};

    if (history && undoingStep === 'armed') {
        // Back where the user was: keep the live page as it is.
        undoingStep = null;
        event.preventDefault();

        return;
    }

    if (bypassNextNavigation) {
        bypassNextNavigation = false;

        return;
    }

    if (history) {
        const dialog = unsaved.openDialog();

        if (dialog) {
            event.preventDefault();
            undoHistoryStep();
            dialog.close();

            return;
        }
    }

    if (!unsaved.pageIsDirty()) return;

    event.preventDefault();
    const step = history ? undoHistoryStep() : 0;

    unsaved.ask().then((discard) => {
        if (!discard) return;

        bypassNextNavigation = true;

        // Take the user's own step again, so history stays as they left it.
        step ? window.history.go(step) : window.Alpine.navigate(url?.href ?? String(url));
    });
});

window.addEventListener('beforeunload', (event) => {
    if (!unsaved.anythingUnsaved()) return;

    // The text is the browser's own; neither of these can change it.
    event.preventDefault();
    event.returnValue = '';
});
