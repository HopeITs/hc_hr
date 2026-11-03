/*
 * The desk's arithmetic (resources/js/dashboard/home-screen.js): where panels
 * go, how a pulled edge changes a size, which way it grows, where a carried
 * panel lands.
 * No DOM here, so it is unit-tested with Node (tests/js/dashboard-layout.test.mjs).
 */

/** "CxR" → [columns, rows]. */
export const parse = (size) => (size || '1x1').split('x').map((n) => parseInt(n, 10) || 1);

/** A size kept inside the panel's bounds, never wider than the grid. */
export function clampSize(bounds, cols, c, r) {
    const b = bounds || { min: [1, 1], max: [1, 1] };
    const maxC = Math.min(b.max[0], cols);

    return [Math.max(Math.min(b.min[0], maxC), Math.min(maxC, c)), Math.max(b.min[1], Math.min(b.max[1], r))];
}

/**
 * The size a panel keeps when the first move or resize starts from what is on
 * screen (home-screen.js bake()): the size it is seen at, so nothing jumps,
 * unless that size only comes from this screen. A phone (4 columns) shows
 * every panel at most 4 wide and grows panels into its own gaps, and any grid
 * narrower than the saved width cuts it down: saving that would overwrite the
 * computer's sizes (calendar 8x3 became 4x3 after a phone only moved a panel).
 * Then the saved size stays; the grid still shows it clamped. A size the
 * person sets by hand (setCells) is saved as set.
 *
 * @param {string} stored  the saved size, "CxR"
 * @param {{w: number, h: number}|undefined} placed  where pack() put it
 * @returns {string}
 */
export function bakedSize(stored, placed, cols) {
    if (! placed || cols <= 4 || parse(stored)[0] > cols) return stored;

    return placed.w + 'x' + placed.h;
}

/**
 * Pulling towards the inline end grows a panel: right in English, left in
 * Arabic. The pointer's x movement is mirrored in RTL; y is the same in both.
 */
export const inlineDelta = (dx, rtl) => dx * (rtl ? -1 : 1);

/**
 * Which way each resize handle grows the panel, as [columns, rows] signs for a
 * pointer moving towards the inline end (inlineDelta) and downwards. The end
 * edge and the corner grow towards the inline end, the start edge towards the
 * inline start, the bottom edge and the corner downwards, the top edge upwards.
 * An unknown edge changes nothing.
 */
export function edgeGrowth(edge) {
    return { end: [1, 0], start: [-1, 0], bottom: [0, 1], top: [0, -1], corner: [1, 1] }[edge] || [0, 0];
}

/** A length in pixels as cells (not rounded): each cell after the first brings one gap with it. */
export const rawCells = (px, cell, gap) => (px + gap) / (cell + gap);

/**
 * A panel carried over another one lands after it once the pointer is past
 * that panel's middle along the reading direction (from the right in Arabic),
 * before it otherwise.
 *
 * @param {{left: number, right: number, width: number}} box  the panel under the pointer
 */
export function dropsAfter(box, x, rtl) {
    if (! (box.width > 0)) return false;
    const along = rtl ? box.right - x : x - box.left;

    return along > box.width / 2;
}

/**
 * The order once `key` is moved just before or just after `target`, or null
 * when that is where it already is (nothing to do, nothing to animate).
 *
 * @param {string[]} keys
 * @returns {string[]|null}
 */
export function reorder(keys, key, target, after) {
    if (key === target || ! keys.includes(key) || ! keys.includes(target)) return null;
    const rest = keys.filter((k) => k !== key);
    const at = rest.indexOf(target) + (after ? 1 : 0);
    const next = [...rest.slice(0, at), key, ...rest.slice(at)];

    return next.every((k, i) => k === keys[i]) ? null : next;
}

/**
 * Cell by cell, with a margin around each cell edge: the size changes only once
 * the pointer is clearly into the next cell, so it never flickers between two.
 */
export const stepCells = (raw, cur) => (raw > cur + 0.6 ? Math.floor(raw + 0.4) : raw < cur - 0.6 ? Math.ceil(raw - 0.4) : cur);

/**
 * Panels a phone (4 columns) shows one row high whatever their saved size: the
 * clock, drawn there as one digital line (dashboard.css, "phone clock").
 */
export const PHONE_ONE_ROW = ['clock'];

/**
 * Every other panel is at least this many rows on a phone: a phone's row is
 * a square cell (~85px), too short for a card's header and a line under it
 * (the late strip was cut off at one row).
 */
export const PHONE_MIN_ROWS = 2;

/**
 * What the packer is given on a phone: the one-row panels as one row, kept
 * from growing taller, so the panels below close up under them instead of
 * leaving the rows the CSS no longer fills; every other panel at least
 * PHONE_MIN_ROWS high. The saved sizes are never changed.
 *
 * @returns {{shown: {key: string, size: string}[], bounds: Object}}
 */
export function phoneView(shown, bounds, cols) {
    if (cols > 4) return { shown, bounds };
    const out = { ...bounds };
    const view = shown.map((i) => {
        const b = bounds[i.key] || { min: [1, 1], max: [1, 1] };
        const [w, h] = parse(i.size);
        if (PHONE_ONE_ROW.includes(i.key)) {
            out[i.key] = { ...b, min: [b.min[0], 1], max: [b.max[0], 1] };

            return h === 1 ? i : { ...i, size: w + 'x1' };
        }
        if (h >= PHONE_MIN_ROWS) return i;
        out[i.key] = { ...b, min: [b.min[0], PHONE_MIN_ROWS], max: [b.max[0], Math.max(b.max[1], PHONE_MIN_ROWS)] };

        return { ...i, size: w + 'x' + PHONE_MIN_ROWS };
    });

    return { shown: view, bounds: out };
}

/**
 * Where every visible panel goes, with no holes left beside them:
 *  1. place panels in order, each in the first free spot that fits (like CSS
 *     "dense");
 *  2. a panel with empty cells beside it grows into them, then into empty
 *     cells below it, never past its own maximum.
 * A panel the person sized by hand (`fixed`) keeps exactly that size. Only
 * what is shown changes; the saved sizes stay as the person set them.
 *
 * @param {{key: string, size: string, fixed?: boolean}[]} shown  the visible panels, in order
 * @returns {Object<string, {x: number, y: number, w: number, h: number}>}
 */
export function pack(shown, cols, bounds) {
    const used = []; // used[row][col] = true
    const free = (x, y, w, h) => {
        if (x + w > cols) return false;
        for (let r = y; r < y + h; r++) for (let c = x; c < x + w; c++) if (used[r] && used[r][c]) return false;

        return true;
    };
    const take = (x, y, w, h) => {
        for (let r = y; r < y + h; r++) {
            used[r] = used[r] || [];
            for (let c = x; c < x + w; c++) used[r][c] = true;
        }
    };
    const out = {};

    for (const it of shown) {
        let [w, h] = parse(it.size);
        w = Math.min(w, cols);
        let y = 0;
        let x = 0;
        for (;; y++) {
            x = 0;
            while (x + w <= cols && ! free(x, y, w, h)) x++;
            if (x + w <= cols) break;
        }
        take(x, y, w, h);
        out[it.key] = { x, y, w, h };
    }

    const rows = used.length;
    const grow = (key, dw, dh) => {
        const p = out[key];
        const max = (bounds[key] || { max: [1, 1] }).max;
        if (p.w + dw > Math.min(max[0], cols) || p.h + dh > max[1] || p.y + p.h + dh > rows) return false;
        const nx = dw ? p.x + p.w : p.x;
        const ny = dh ? p.y + p.h : p.y;
        if (! free(nx, ny, dw || p.w, dh || p.h)) return false;
        take(nx, ny, dw || p.w, dh || p.h);
        p.w += dw;
        p.h += dh;

        return true;
    };
    // A panel sized by hand keeps that size; the others grow into the gaps.
    const growing = shown.filter((it) => ! it.fixed).map((it) => it.key);
    for (const key of growing) while (grow(key, 1, 0));
    for (const key of growing) while (grow(key, 0, 1));

    return out;
}
