/**
 * On-demand loader for the amCharts 5 dashboard charts.
 *
 * WHY
 * ---
 * amCharts 5 is by far the largest dependency in the bundle, and it is used by
 * exactly three components — all of them on the dashboard. Importing them from
 * app.js meant every single page paid for it: the login screen, every table,
 * every form. Because Vite emitted one chunk, there was no way for the browser
 * to skip it.
 *
 * Each entry below is a dynamic import(), which Vite splits into its own chunk.
 * The chunk is only requested once its host element is actually in the DOM, so
 * non-dashboard pages never download amCharts at all.
 *
 * Chart.js is deliberately NOT handled here. Five Blade views instantiate it
 * from inline <script> blocks that run immediately and depend on window.Chart
 * already existing, so it has to stay eagerly loaded in app.js. Moving those
 * views to this loader would be a prerequisite for splitting it out too.
 *
 * Nothing here changes chart appearance or behaviour — only *when* the code
 * that draws them arrives.
 */

import { onThemeChange } from "./brand-colors.js";

const CHARTS = [
    {
        selector: "#chartdiv",
        load: () => import("../contract-type-employees-chart.js"),
    },
    {
        selector: "#salaryTypePieChart",
        load: () => import("../donut-chart.js"),
    },
    {
        selector: "#jobLevelsChart",
        load: () => import("../job-levels-employees.js"),
    },
];

/**
 * Chunks already fetched, keyed by selector.
 *
 * import() caches the module itself, but holding the promise here lets repeated
 * calls (a Livewire re-render storm, say) await the same in-flight request
 * instead of queueing several inits against a half-loaded module.
 */
const loaded = new Map();

/**
 * Hosts already drawn, with the data they were drawn from. A Livewire update
 * that did not touch a chart (a dashboard layout save, a note) leaves it
 * alone instead of redrawing it, and replaying its animation, every time;
 * amCharts follows its host's size by itself, so a resized dashboard tile
 * needs no redraw either. A host that was replaced, emptied by a morph, or
 * given new data is drawn again.
 */
const drawn = new WeakMap();

function renderVisibleCharts(force = false) {
    for (const { selector, load } of CHARTS) {
        const host = document.querySelector(selector);

        if (!host) {
            continue;
        }

        const data = JSON.stringify(host.dataset);

        if (force !== true && drawn.get(host) === data && host.childElementCount > 0) {
            continue;
        }

        if (!loaded.has(selector)) {
            loaded.set(selector, load());
        }

        loaded
            .get(selector)
            .then((module) => {
                module.default?.();
                drawn.set(host, data);
            })
            .catch((error) => {
                // A failed chunk must not take the rest of the dashboard down.
                // Drop the cached promise so a later navigation can retry.
                loaded.delete(selector);
                console.error(`Failed to load chart for ${selector}`, error);
            });
    }
}

export default function registerLazyCharts() {
    // The dashboard markup may already be parsed by the time this module runs.
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", renderVisibleCharts);
    } else {
        renderVisibleCharts();
    }

    // Livewire swaps DOM without a page load, so re-check after each update.
    // `livewire:navigated` covers wire:navigate page changes; the commit hook
    // covers in-place component re-renders (a filter changing, for instance).
    document.addEventListener("livewire:navigated", renderVisibleCharts);

    // wire:navigate removes the chart hosts without telling amCharts, which
    // keeps every Root (and its animation loop) alive in its registry. Dispose
    // them before the swap; the module is only imported if a chart ever loaded.
    document.addEventListener("livewire:navigating", () => {
        if (loaded.size === 0) {
            return;
        }

        import("@amcharts/amcharts5").then((am5) => {
            [...am5.registry.rootElements].forEach((root) => root.dispose());
        });
    });

    // Light <-> dark: redraw, so every chart picks up the other neutrals. Each
    // chart module disposes its previous Root before drawing again.
    onThemeChange(() => {
        if (loaded.size > 0) {
            renderVisibleCharts(true);
        }
    });

    window.Livewire?.hook("commit", ({ succeed }) => {
        succeed(() => queueMicrotask(renderVisibleCharts));
    });
}
