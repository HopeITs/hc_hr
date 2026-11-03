import 'clock-timepicker';

function normalizeTime(value) {
    if (!value) {
        return '';
    }

    const match = String(value).match(/^(\d{1,2}):(\d{2})/);

    return match ? `${match[1].padStart(2, '0')}:${match[2]}` : value;
}

function bindPicker(picker) {
    const hiddenId = picker.dataset.bindTarget;
    if (!hiddenId) {
        return;
    }

    const hidden = document.getElementById(hiddenId);
    if (!hidden) {
        return;
    }

    const normalized = normalizeTime(hidden.value);
    if (normalized && picker.value !== normalized) {
        picker.value = normalized;
    }

    if (picker.dataset.bound === '1') {
        return;
    }

    const setHidden = (val) => {
        hidden.value = val || '';
        hidden.dispatchEvent(new Event('input', { bubbles: true }));
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
    };

    picker.addEventListener('change', () => setHidden(picker.value || ''));
    picker.addEventListener('input', () => setHidden(picker.value || ''));

    picker.dataset.bound = '1';
}

const clamp = (value, min, max) => Math.min(Math.max(value, min), Math.max(min, max));

/**
 * Keeps the open clock inside the viewport. The library skips positioning
 * when any ancestor is position: fixed (every modal here), so in a dialog
 * the clock kept a stale spot and ran off the bottom of the screen. Place it
 * below the field, or above when there is more room there, aligned to the
 * field's inline start (RTL aware), centred on phones as the library does.
 * The popup is position: fixed, but a transformed ancestor (the dialog)
 * becomes its containing block; the second pass corrects for that offset.
 */
function placePopup(picker) {
    const popup = picker._popup ?? picker.shadowRoot?.querySelector('div.popup');
    const input = picker._input ?? picker.querySelector('input');
    if (!popup || !input || popup.style.pointerEvents !== 'all') {
        return;
    }

    const margin = 8;
    const gap = 4;
    const field = input.getBoundingClientRect();
    const width = popup.offsetWidth;
    const height = popup.offsetHeight;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    let top;
    let left;

    if (vw < 600) {
        top = (vh - height) / 2;
        left = (vw - width) / 2;
    } else {
        const below = vh - field.bottom - margin;
        const above = field.top - margin;
        top = below >= height + gap || below >= above ? field.bottom + gap : field.top - height - gap;
        left = getComputedStyle(input).direction === 'rtl' ? field.right - width : field.left;
    }

    top = clamp(top, margin, vh - height - margin);
    left = clamp(left, margin, vw - width - margin);

    popup.style.top = `${top}px`;
    popup.style.left = `${left}px`;

    const placed = popup.getBoundingClientRect();
    popup.style.top = `${top + (top - placed.top)}px`;
    popup.style.left = `${left + (left - placed.left)}px`;
}

function patchPositioning() {
    const proto = customElements.get('clock-timepicker')?.prototype;
    if (!proto || proto.__hcPlaced || typeof proto.positionPopup !== 'function') {
        return;
    }

    const original = proto.positionPopup;
    proto.positionPopup = function (...args) {
        original.apply(this, args);
        placePopup(this);
    };
    proto.__hcPlaced = true;
}

export function initClockTimepickers(root = document) {
    const run = () => {
        root.querySelectorAll('clock-timepicker[data-bind-target]').forEach(bindPicker);
    };

    customElements.whenDefined('clock-timepicker').then(() => {
        patchPositioning();
        run();
        requestAnimationFrame(run);
    });
}
