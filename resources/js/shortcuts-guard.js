/**
 * Keyboard shortcuts (prompt 10): which key presses are shortcuts at all, and
 * the "g then a letter" sequence. Pure functions with no document or window,
 * so they are unit-tested without a browser: tests/js/shortcuts-guard.test.mjs
 * (`npm run test:js`). resources/js/commands.js wires them to the page.
 */

/** "g" then the letter must come within this many milliseconds. */
export const SEQUENCE_TIMEOUT = 1500;

/** No "g" pressed yet. */
export const IDLE = Object.freeze({ pending: false, at: 0 });

const FIELDS = new Set(['INPUT', 'TEXTAREA', 'SELECT']);

// Widgets whose own keys a letter belongs to (type-ahead in menus and lists,
// everything in a dialog).
const WIDGET_ROLES = new Set(['dialog', 'alertdialog', 'menu', 'menubar', 'listbox', 'combobox', 'application']);

const MODIFIER_KEYS = new Set(['Shift', 'Control', 'Alt', 'AltGraph', 'Meta', 'CapsLock', 'Fn', 'OS']);

function* selfAndAncestors(element) {
    for (let node = element; node && node.nodeType === 1; node = node.parentElement) {
        yield node;
    }
}

/** Is this where someone types: a field, a select, or editable content (also inherited)? */
export function isTypingTarget(element) {
    for (const node of selfAndAncestors(element)) {
        if (FIELDS.has(node.tagName) || node.isContentEditable === true) return true;

        const editable = node.getAttribute?.('contenteditable');
        if (editable !== null && editable !== undefined && editable.toLowerCase() !== 'false') return true;
    }

    return false;
}

/** Is this inside a dialog, menu or list box, whose keys are its own? */
export function isInsideWidget(element) {
    for (const node of selfAndAncestors(element)) {
        if (WIDGET_ROLES.has(node.getAttribute?.('role')) || node.getAttribute?.('aria-modal') === 'true') return true;
    }

    return false;
}

/** A modifier pressed on its own (Shift on the way to "?"); it changes nothing. */
export function isModifierKey(event) {
    return MODIFIER_KEYS.has(event?.key);
}

/**
 * A key press the page shortcuts must leave alone: someone typing, a key held
 * down, a combination with Ctrl, Cmd or Alt (the browser's and the system's),
 * or a key that a dialog, menu or list on screen already handles.
 */
export function shouldIgnore(event) {
    if (!event || event.defaultPrevented || event.isComposing || event.repeat) return true;
    if (event.ctrlKey || event.metaKey || event.altKey) return true;

    return isTypingTarget(event.target) || isInsideWidget(event.target);
}

/** "?" opens the list of shortcuts; an Arabic layout types «؟» on the same key. */
export function isSheetKey(event) {
    return event?.key === '?' || event?.key === '؟';
}

/**
 * The latin letter a key stands for, or null. With an Arabic layout the G key
 * reports «ل», so the physical key (KeyG) decides then; Shift (capitals) is
 * not a shortcut.
 */
export function letterOf(event) {
    if (!event || event.shiftKey) return null;

    const key = String(event.key ?? '');
    if (/^[a-z]$/i.test(key)) return key.toLowerCase();

    const physical = /^Key([A-Z])$/.exec(String(event.code ?? ''));

    return physical && key.length === 1 ? physical[1].toLowerCase() : null;
}

/**
 * One key of the "g then a letter" sequence. Returns the next state and, when
 * a pair is complete, the letter after "g". Anything else (another letter, too
 * late, a non-letter) starts over.
 *
 * @param {{pending: boolean, at: number}} state
 * @param {string|null} letter from letterOf()
 * @param {number} now milliseconds
 * @returns {{state: {pending: boolean, at: number}, go: string|null}}
 */
export function step(state, letter, now, timeout = SEQUENCE_TIMEOUT) {
    const waiting = Boolean(state?.pending) && now - state.at <= timeout;

    if (waiting && letter && letter !== 'g') return { state: IDLE, go: letter };
    if (letter === 'g') return { state: { pending: true, at: now }, go: null };

    return { state: IDLE, go: null };
}
