/**
 * The identity palette, as chart libraries need it: plain hex strings read
 * from the brand variables in resources/css/app.css. Charts read nothing else:
 * no other hue exists in the identity, not even for a chart.
 *
 * Every call re-reads the variables, so a chart drawn after a theme change
 * (light <-> dark) already gets the right neutrals. onThemeChange() tells
 * already-drawn charts when that happens. The literal fallback covers a
 * stylesheet that has not applied yet, or a token that stops being plain hex.
 */
const TOKENS = {
    primary: ["--color-primary", "#be1f24"],
    ink: ["--color-ink", "#414042"],
    ink2: ["--color-ink-2", "#6d6e71"],
    ink3: ["--color-ink-3", "#939598"],
    line: ["--color-line", "#bcbec0"],
    lineSoft: ["--color-line-soft", "#e6e7e8"],
    surface: ["--color-surface", "#e6e7e8"],
    card: ["--color-card", "#ffffff"],
    onInk: ["--color-on-ink", "#ffffff"],
    onBrand: ["--color-on-brand", "#ffffff"],
};

const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

export function brandColors() {
    const rootStyle = getComputedStyle(document.documentElement);

    const colors = Object.fromEntries(
        Object.entries(TOKENS).map(([name, [token, fallback]]) => {
            const value = rootStyle.getPropertyValue(token).trim();

            return [name, HEX.test(value) ? value : fallback];
        })
    );

    // Names the existing chart modules already use.
    colors.secondaryDark = colors.ink;
    colors.secondaryMedium = colors.ink2;
    colors.secondaryLight = colors.ink3;
    colors.secondarySoft = colors.surface;

    return colors;
}

// Series order: neutrals only. Red has three jobs (the primary action, what is
// current, what waits on the viewer or is late) and telling series apart is not
// one of them, so a chart separates its series by these steps and its labels.
// A chart with more than four categories repeats the order, which keeps
// neighbouring slices and bars distinguishable in both themes. Legends drawn in
// Blade use the same order: bg-ink, bg-ink-3, bg-line, bg-ink-2.
export function brandPalette() {
    const colors = brandColors();

    return [colors.ink, colors.ink3, colors.line, colors.ink2];
}

export function isDarkTheme() {
    const chosen = document.documentElement.dataset.theme;

    return chosen ? chosen === "dark" : window.matchMedia("(prefers-color-scheme: dark)").matches;
}

/**
 * Calls `callback(colors)` whenever the effective theme changes: the person's
 * switch (data-theme on <html>) or the system setting. Returns an unsubscribe.
 */
export function onThemeChange(callback) {
    let dark = isDarkTheme();

    const check = () => {
        if (isDarkTheme() !== dark) {
            dark = !dark;
            callback(brandColors());
        }
    };

    const observer = new MutationObserver(check);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });

    const media = window.matchMedia("(prefers-color-scheme: dark)");
    media.addEventListener("change", check);

    return () => {
        observer.disconnect();
        media.removeEventListener("change", check);
    };
}
