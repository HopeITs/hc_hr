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

export function initClockTimepickers(root = document) {
    const run = () => {
        root.querySelectorAll('clock-timepicker[data-bind-target]').forEach(bindPicker);
    };

    customElements.whenDefined('clock-timepicker').then(() => {
        run();
        requestAnimationFrame(run);
    });
}
