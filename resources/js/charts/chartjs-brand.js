import { brandColors, onThemeChange } from "./brand-colors.js";

/**
 * Chart.js draws anything a chart leaves unset in its own defaults: #666 text,
 * black grid lines and tooltips, and hover colours it derives by saturating and
 * darkening the base colour, which takes a brand red off the palette.
 */
function apply(Chart, colors) {
    Chart.defaults.color = colors.ink2;
    Chart.defaults.borderColor = colors.lineSoft;
    Chart.defaults.backgroundColor = colors.surface;

    Chart.defaults.hoverColor = (ctx, options) => options.color;
    Chart.defaults.hoverBorderColor = (ctx, options) => options.borderColor;
    Chart.defaults.hoverBackgroundColor = (ctx, options) => options.backgroundColor;

    Object.assign(Chart.defaults.plugins.tooltip, {
        backgroundColor: colors.ink,
        borderColor: colors.ink,
        titleColor: colors.onInk,
        bodyColor: colors.onInk,
        footerColor: colors.onInk,
        multiKeyBackground: colors.onInk,
    });
}

export default function applyChartJsBrandDefaults(Chart) {
    apply(Chart, brandColors());

    // The dashboard's inline Chart.js cards (Blade @script blocks) colour their
    // marks from here too: scriptable options calling window.brandColors() are
    // re-resolved by the update below, so they follow light / dark.
    window.brandColors = brandColors;

    // Light <-> dark: new defaults, and every chart on the page redrawn with them.
    onThemeChange((colors) => {
        apply(Chart, colors);
        Object.values(Chart.instances).forEach((chart) => chart.update("none"));
    });
}
