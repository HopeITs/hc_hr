/**
 * Application entry point.
 *
 * Everything imported here ships in the shared bundle that loads on EVERY page,
 * so only genuinely global dependencies belong in this file. Page-specific
 * libraries go behind a dynamic import — see charts/lazy-charts.js.
 *
 * jQuery is not here by preference: select2 requires it, and select2 is used by
 * 20+ Blade views. Removing either is a UI migration, not a build change.
 */

import jQuery from 'jquery';

window.$ = window.jQuery = jQuery;

import select2 from 'select2';

select2();

// select2's own messages ("No results found", "Searching…") in the page's
// language. The strings come from resources/lang through a meta tag in the
// head (partials/head.blade.php); without it select2 keeps its English ones.
(() => {
    let messages = {};
    try {
        messages = JSON.parse(document.querySelector('meta[name="hc-select2-i18n"]')?.content || '{}');
    } catch {
        return;
    }
    if (!messages.noResults) return;

    const count = (text, n) => String(text).replace(':count', n);

    $.fn.select2.defaults.set('language', {
        errorLoading: () => messages.errorLoading,
        inputTooLong: (args) => count(messages.inputTooLong, args.input.length - args.maximum),
        inputTooShort: (args) => count(messages.inputTooShort, args.minimum - args.input.length),
        loadingMore: () => messages.loadingMore,
        maximumSelected: (args) => count(messages.maximumSelected, args.maximum),
        noResults: () => messages.noResults,
        searching: () => messages.searching,
        removeAllItems: () => messages.removeAllItems,
        removeItem: () => messages.removeItem,
        search: () => messages.search,
    });
})();

// The single-page composer behind both creating and editing a contract.
import './contract-composer.js';

// Context-aware "Take a tour" walkthrough; steps live in config/tours.php.
import './product-tour.js';

import '../css/app.css';
import { initFlowbite } from 'flowbite';
import 'flowbite';

/**
 * Chart.js stays eagerly loaded and exposed on `window`.
 *
 * Five Blade views (the dashboard statistic cards) call `new Chart(...)` from
 * inline <script> blocks that execute during parse, so the constructor must
 * already be present — a dynamic import would resolve too late. Only the
 * controllers, elements and scales those views actually use are registered,
 * which is why this imports from 'chart.js' rather than 'chart.js/auto'
 * (the /auto entry point pulls in every controller Chart.js ships).
 */
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Title,
    Tooltip,
} from 'chart.js';
import applyChartJsBrandDefaults from './charts/chartjs-brand.js';

Chart.register(
    DoughnutController,
    BarController,
    LineController,
    BarElement,
    LineElement,
    ArcElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
    Title,
);

applyChartJsBrandDefaults(Chart);

window.Chart = Chart;

// Toasts and the confirmation dialog (<x-toasts/>, <x-confirm-dialog/>).
import './feedback.js';

import './password-toggle.js';
import './password-strength.js';
import './scatter-chart.js';
import { initClockTimepickers } from './clock-timepicker.js';

// amCharts 5 is dashboard-only and the single heaviest dependency, so its three
// chart modules are fetched on demand instead of being bundled in here.
import registerLazyCharts from './charts/lazy-charts.js';

registerLazyCharts();

document.addEventListener('DOMContentLoaded', () => initClockTimepickers());

// Flowbite binds its behaviour on load, but Livewire replaces DOM afterwards,
// so any swapped-in dropdown/modal/tooltip has to be re-initialised.
const registerLivewireHooks = () => {
    if (!window.Livewire) return;

    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            queueMicrotask(() => {
                initFlowbite();
                initClockTimepickers();
            });
        });
    });
};

if (window.Livewire) {
    registerLivewireHooks();
} else {
    document.addEventListener('livewire:init', registerLivewireHooks, { once: true });
}

// Chart.js instances created by the inline dashboard scripts are bound to
// canvases that wire:navigate is about to remove; destroy them so their
// resize observers and animation frames do not outlive the page.
document.addEventListener('livewire:navigating', () => {
    Object.values(Chart.instances).forEach((chart) => chart.destroy());
});

document.addEventListener('livewire:navigated', () => {
    initFlowbite();
    initClockTimepickers();
});

// A Livewire action that opens or closes an inline panel names the element
// that should hold focus next: `$this->dispatch('focus-element', id: '…')`.
// Without it, removing the button that had focus drops a keyboard user back
// to the top of the page. Livewire fires dispatches after the DOM is patched,
// so the target already exists; scrolling goes through scrollIntoView so the
// target's scroll-margin clears the fixed top bar.
window.addEventListener('focus-element', (event) => {
    const target = document.getElementById(event.detail?.id);
    if (!target) return;

    target.focus({ preventScroll: true });
    target.scrollIntoView({ block: 'nearest' });

    // A target without its own scroll-margin can still land under the bar.
    const bar = document.querySelector('nav.fixed')?.getBoundingClientRect().bottom ?? 0;
    const top = target.getBoundingClientRect().top;
    if (top < bar) window.scrollBy({ top: top - bar - 16 });
});

// When a Select2 dropdown opens, flag its results list with `.results--scroll`
// only if it actually scrolls, so the hover highlight can be trimmed on the
// scrollbar side (see `.results--scroll` rule in app.css).
$(document).on('select2:open', function () {
    requestAnimationFrame(function () {
        document.querySelectorAll('.select2-results__options').forEach(function (ul) {
            ul.classList.toggle('results--scroll', ul.scrollHeight > ul.clientHeight + 1);
        });
    });
});
