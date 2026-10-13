import { brandColors } from "./brand-colors.js";

/**
 * Chart.js draws anything a chart leaves unset in its own defaults: #666 text,
 * black grid lines and tooltips, and hover colours it derives by saturating and
 * darkening the base colour, which takes a brand red off the palette.
 */
export default function applyChartJsBrandDefaults(Chart) {
    const colors = brandColors();

    Chart.defaults.color = colors.secondaryMedium;
    Chart.defaults.borderColor = colors.secondarySoft;
    Chart.defaults.backgroundColor = colors.secondarySoft;

    Chart.defaults.hoverColor = (ctx, options) => options.color;
    Chart.defaults.hoverBorderColor = (ctx, options) => options.borderColor;
    Chart.defaults.hoverBackgroundColor = (ctx, options) => options.backgroundColor;

    Object.assign(Chart.defaults.plugins.tooltip, {
        backgroundColor: colors.secondaryDark,
        borderColor: colors.secondaryDark,
        titleColor: "#ffffff",
        bodyColor: "#ffffff",
        footerColor: "#ffffff",
        multiKeyBackground: "#ffffff",
    });
}
