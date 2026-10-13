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

window.Chart = Chart;

import Swal from 'sweetalert2';

window.Swal = Swal;

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
Livewire.hook('commit', ({ succeed }) => {
    succeed(() => {
        queueMicrotask(() => {
            initFlowbite();
            initClockTimepickers();
        });
    });
});

document.addEventListener('livewire:navigated', () => {
    initFlowbite();
    initClockTimepickers();
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
