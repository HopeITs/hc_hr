import * as am5 from "@amcharts/amcharts5";
import { brandColors, brandPalette } from "./brand-colors.js";

/**
 * amCharts falls back to its interface colours (blue and green buttons, black
 * grid, text and tooltip text) for anything a chart does not colour itself.
 * Call right after Root.new(), before any chart is created on that root.
 */
export function applyBrandInterfaceColors(root) {
    const colors = brandColors();
    const primary = am5.color(colors.primary);
    const dark = am5.color(colors.secondaryDark);
    const medium = am5.color(colors.secondaryMedium);
    const light = am5.color(colors.secondaryLight);
    const soft = am5.color(colors.secondarySoft);
    const white = am5.color("#ffffff");

    root.interfaceColors.setAll({
        stroke: soft,
        fill: soft,
        primaryButton: primary,
        primaryButtonHover: primary,
        primaryButtonDown: primary,
        primaryButtonActive: primary,
        primaryButtonDisabled: soft,
        primaryButtonTextDisabled: white,
        primaryButtonText: white,
        primaryButtonStroke: white,
        secondaryButton: soft,
        secondaryButtonHover: light,
        secondaryButtonDown: medium,
        secondaryButtonActive: soft,
        secondaryButtonText: dark,
        secondaryButtonStroke: white,
        grid: dark,
        background: white,
        alternativeBackground: dark,
        text: dark,
        alternativeText: white,
        disabled: light,
        positive: dark,
        negative: primary,
    });
}

// The default ColorSet starts from a blue and shifts its hue for every new
// series or slice; `reuse` cycles the brand palette instead.
export function brandColorSet(root) {
    return am5.ColorSet.new(root, {
        colors: brandPalette().map((color) => am5.color(color)),
        reuse: true,
    });
}
