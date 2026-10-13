/**
 * The identity palette, as chart libraries need it: plain hex strings.
 *
 * Only these five tokens hold hex values. The neutral, hover and selection
 * tokens are color-mix() expressions that no chart library can parse, so charts
 * must never read them. The literal fallback covers a stylesheet that has not
 * applied yet, or a token that stops being a plain hex value.
 */
const TOKENS = {
    primary: ["--color-primary", "#be1f24"],
    secondaryDark: ["--color-secondary-dark", "#58595b"],
    secondaryMedium: ["--color-secondary-medium", "#6d6e71"],
    secondaryLight: ["--color-secondary-light", "#939598"],
    secondarySoft: ["--color-secondary-soft", "#dcddde"],
};

const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

export function brandColors() {
    const rootStyle = getComputedStyle(document.documentElement);

    return Object.fromEntries(
        Object.entries(TOKENS).map(([name, [token, fallback]]) => {
            const value = rootStyle.getPropertyValue(token).trim();

            return [name, HEX.test(value) ? value : fallback];
        })
    );
}

// Series order. A chart with more than five categories repeats it, and this
// order keeps neighbouring slices and bars distinguishable.
export function brandPalette() {
    const colors = brandColors();

    return [
        colors.primary,
        colors.secondaryDark,
        colors.secondaryMedium,
        colors.secondaryLight,
        colors.secondarySoft,
    ];
}
