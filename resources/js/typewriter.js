/**
 * Typed examples in an empty field's placeholder (ported from media-request's
 * mrTypewriter).
 *
 *   <input data-typewriter='["Annual leave for a family visit", "…"]' placeholder="…">
 *
 * <x-forms.input :examples="…"> and <x-forms.textarea :examples="…"> write the
 * attribute; any other field can carry it by hand. While the field is empty and
 * not focused, its placeholder types one example after another. Rules:
 *   - only the placeholder changes, never the value (nothing reaches wire:model);
 *   - focus or any text in the field stops the typing and puts the field's own
 *     static placeholder back (the one the server rendered), so a Livewire
 *     re-render that restores that attribute changes nothing on screen; the
 *     typing starts the current example again once the field is empty and left;
 *   - prefers-reduced-motion: the first example, still, and nothing more;
 *   - a hidden tab pauses; a field removed from the page ends its loop.
 * Plain DOM, no Alpine: these fields often sit inside an element that already
 * has its own x-data, and an extra component on the input would fight it.
 */

const bound = new WeakSet();
const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

function readExamples(el) {
    try {
        const list = JSON.parse(el.dataset.typewriter || '[]');
        return Array.isArray(list) ? list.filter((s) => typeof s === 'string' && s.trim() !== '') : [];
    } catch {
        return [];
    }
}

function bind(el) {
    if (bound.has(el)) return;
    const list = readExamples(el);
    if (!list.length) return;
    bound.add(el);
    // The server-rendered placeholder: what a Livewire morph puts back, so it is
    // also what a focused or filled field shows (no flicker on re-render).
    const original = el.getAttribute('placeholder') ?? '';

    if (reducedMotion()) {
        el.placeholder = list[0];
        return;
    }

    // Array.from splits by code point, so an emoji or an Arabic letter with a
    // combining mark is never cut in half; Arabic joins and runs right to left
    // on its own because the field carries dir.
    const chars = list.map((s) => Array.from(s));
    let i = 0;
    let n = 0;
    let back = false;
    let timer = null;

    const schedule = (ms) => {
        timer = setTimeout(tick, ms);
    };

    function tick() {
        if (!el.isConnected) {
            bound.delete(el);
            return;
        }
        // A hidden tab: check again later, keep the place.
        if (document.hidden) return schedule(1000);
        // The person is in the field or has written something: wait quietly
        // with the static placeholder showing, and type afresh afterwards.
        if (document.activeElement === el || el.value) {
            if (el.placeholder !== original) el.placeholder = original;
            n = 0;
            back = false;
            return schedule(700);
        }

        const text = chars[i];
        n += back ? -1 : 1;
        el.placeholder = text.slice(0, n).join('') + (n < text.length || back ? '|' : '');

        let wait = back ? 28 : 60 + Math.random() * 50;
        if (!back && n >= text.length) {
            back = true;
            wait = 1800;
        } else if (back && n <= 0) {
            back = false;
            i = (i + 1) % list.length;
            wait = 400;
        }
        schedule(wait);
    }

    // Stop at once on focus or typing, without waiting for the next tick.
    const settle = () => {
        clearTimeout(timer);
        if (el.placeholder !== original) el.placeholder = original;
        n = 0;
        back = false;
        schedule(700);
    };
    el.addEventListener('focus', settle);
    el.addEventListener('input', settle);

    schedule(600);
}

export function initTypewriters(root = document) {
    root.querySelectorAll?.('[data-typewriter]').forEach(bind);
}

window.hcInitTypewriters = initTypewriters;

document.addEventListener('DOMContentLoaded', () => initTypewriters());
document.addEventListener('livewire:navigated', () => initTypewriters());

// Fields that arrive in a Livewire update (a modal opening, a tab switch).
const hookCommits = () =>
    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(() => queueMicrotask(() => initTypewriters()));
    });

if (window.Livewire?.hook) hookCommits();
else document.addEventListener('livewire:init', hookCommits, { once: true });
