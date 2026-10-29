import * as am5 from "@amcharts/amcharts5";
import { brandColors, brandPalette } from "./brand-colors.js";

function apply(root, colors) {
    const primary = am5.color(colors.primary);
    const ink = am5.color(colors.ink);
    const ink2 = am5.color(colors.ink2);
    const ink3 = am5.color(colors.ink3);
    const line = am5.color(colors.lineSoft);
    const card = am5.color(colors.card);
    const onBrand = am5.color("#ffffff");

    root.interfaceColors.setAll({
        stroke: line,
        fill: line,
        primaryButton: primary,
        primaryButtonHover: primary,
        primaryButtonDown: primary,
        primaryButtonActive: primary,
        primaryButtonDisabled: line,
        primaryButtonTextDisabled: ink3,
        primaryButtonText: onBrand,
        primaryButtonStroke: card,
        secondaryButton: line,
        secondaryButtonHover: ink3,
        secondaryButtonDown: ink2,
        secondaryButtonActive: line,
        secondaryButtonText: ink,
        secondaryButtonStroke: card,
        grid: ink,
        background: card,
        alternativeBackground: ink,
        text: ink,
        alternativeText: card,
        disabled: ink3,
        positive: ink,
        negative: primary,
    });
}

/**
 * amCharts falls back to its interface colours (blue and green buttons, black
 * grid, text and tooltip text) for anything a chart does not colour itself.
 * Call right after Root.new(), before any chart is created on that root. On a
 * theme change charts/lazy-charts.js redraws every chart, which calls this again.
 */
export function applyBrandInterfaceColors(root) {
    apply(root, brandColors());
}

// The default ColorSet starts from a blue and shifts its hue for every new
// series or slice; `reuse` cycles the brand palette instead.
export function brandColorSet(root) {
    return am5.ColorSet.new(root, {
        colors: brandPalette().map((color) => am5.color(color)),
        reuse: true,
    });
}
