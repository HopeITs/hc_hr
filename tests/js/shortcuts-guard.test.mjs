// Prompt 10: the keyboard shortcut guard (resources/js/shortcuts-guard.js).
// Node's built-in runner, no dependency: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    IDLE,
    SEQUENCE_TIMEOUT,
    isInsideWidget,
    isModifierKey,
    isSheetKey,
    isTypingTarget,
    letterOf,
    shouldIgnore,
    step,
} from '../../resources/js/shortcuts-guard.js';

/** A stand-in for a DOM element: tag, attributes, parent. */
function el(tagName, attributes = {}, parentElement = null, extra = {}) {
    return {
        nodeType: 1,
        tagName,
        parentElement,
        getAttribute: (name) => (name in attributes ? attributes[name] : null),
        ...extra,
    };
}

const page = el('MAIN');
const key = (k, extra = {}) => ({ key: k, code: '', target: page, ...extra });

test('typing in a field, a select or editable content is not a shortcut', () => {
    for (const tag of ['INPUT', 'TEXTAREA', 'SELECT']) {
        assert.equal(isTypingTarget(el(tag, {}, page)), true, tag);
        assert.equal(shouldIgnore(key('g', { target: el(tag, {}, page) })), true, tag);
    }

    const editor = el('DIV', { contenteditable: 'true' }, page);
    assert.equal(isTypingTarget(editor), true);
    // Inherited: a paragraph inside the editor.
    assert.equal(isTypingTarget(el('P', {}, editor)), true);
    assert.equal(isTypingTarget(el('DIV', { contenteditable: '' }, page)), true);
    assert.equal(isTypingTarget(el('SPAN', {}, page, { isContentEditable: true })), true);
    assert.equal(isTypingTarget(el('DIV', { contenteditable: 'false' }, page)), false);

    assert.equal(isTypingTarget(el('BUTTON', {}, page)), false);
    assert.equal(isTypingTarget(page), false);
    assert.equal(isTypingTarget(null), false);
});

test('combinations with Ctrl, Cmd or Alt, held keys and IME composition are left alone', () => {
    assert.equal(shouldIgnore(key('g', { ctrlKey: true })), true);
    assert.equal(shouldIgnore(key('g', { metaKey: true })), true);
    assert.equal(shouldIgnore(key('g', { altKey: true })), true);
    assert.equal(shouldIgnore(key('g', { repeat: true })), true);
    assert.equal(shouldIgnore(key('g', { isComposing: true })), true);
    assert.equal(shouldIgnore(key('g', { defaultPrevented: true })), true);
    assert.equal(shouldIgnore(null), true);

    assert.equal(shouldIgnore(key('g')), false);
    // Shift alone is how "?" is typed: not a reason to ignore.
    assert.equal(shouldIgnore(key('?', { shiftKey: true })), false);
    assert.equal(isModifierKey(key('Shift')), true);
    assert.equal(isModifierKey(key('g')), false);
});

test('keys inside a dialog, menu or list box belong to it', () => {
    const dialog = el('SECTION', { role: 'dialog', 'aria-modal': 'true' }, page);
    const button = el('BUTTON', {}, dialog);
    assert.equal(isInsideWidget(button), true);
    assert.equal(shouldIgnore(key('g', { target: button })), true);

    assert.equal(isInsideWidget(el('A', {}, el('DIV', { role: 'menu' }, page))), true);
    assert.equal(isInsideWidget(el('LI', {}, el('UL', { role: 'listbox' }, page))), true);
    assert.equal(isInsideWidget(el('DIV', { 'aria-modal': 'true' }, page)), true);
    assert.equal(isInsideWidget(el('A', {}, el('NAV', { role: 'navigation' }, page))), false);
});

test('a letter is read from the key, or from the physical key on an Arabic layout', () => {
    assert.equal(letterOf(key('g')), 'g');
    assert.equal(letterOf(key('G')), 'g'); // Caps Lock
    assert.equal(letterOf(key('G', { shiftKey: true })), null);
    // Arabic layout: the G key types «ل», the D key «ي».
    assert.equal(letterOf(key('ل', { code: 'KeyG' })), 'g');
    assert.equal(letterOf(key('ي', { code: 'KeyD' })), 'd');
    assert.equal(letterOf(key('Enter', { code: 'Enter' })), null);
    assert.equal(letterOf(key('1', { code: 'Digit1' })), null);
    assert.equal(letterOf(null), null);

    assert.equal(isSheetKey(key('?', { shiftKey: true })), true);
    assert.equal(isSheetKey(key('؟', { shiftKey: true })), true);
    assert.equal(isSheetKey(key('/')), false);
});

test('"g" then a letter, in time, is a go-to; anything else starts over', () => {
    let { state, go } = step(IDLE, 'g', 1000);
    assert.deepEqual(state, { pending: true, at: 1000 });
    assert.equal(go, null);

    ({ state, go } = step(state, 'e', 1500));
    assert.equal(go, 'e');
    assert.deepEqual(state, IDLE);

    // Too late.
    ({ state } = step(IDLE, 'g', 0));
    ({ state, go } = step(state, 'e', SEQUENCE_TIMEOUT + 1));
    assert.equal(go, null);
    assert.deepEqual(state, IDLE);

    // A letter without "g" first does nothing.
    assert.equal(step(IDLE, 'e', 10).go, null);

    // "g g d": the second "g" starts over, then "d" completes.
    ({ state } = step(IDLE, 'g', 0));
    ({ state } = step(state, 'g', 100));
    assert.equal(step(state, 'd', 200).go, 'd');

    // A non-letter in between cancels.
    ({ state } = step(IDLE, 'g', 0));
    ({ state, go } = step(state, null, 50));
    assert.deepEqual(state, IDLE);
    assert.equal(step(state, 'd', 60).go, null);
});
