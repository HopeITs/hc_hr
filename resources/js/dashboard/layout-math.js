/*
 * The home screen's arithmetic (resources/js/dashboard/home-screen.js): where
 * tiles go, how a drag or a key changes a size, which way the arrows point.
 * No DOM here, so it is unit-tested with Node (tests/js/dashboard-layout.test.mjs).
 */

export const isApp = (key) => key.startsWith('app.');

/** "CxR" → [columns, rows]. */
export const parse = (size) => (size || '1x1').split('x').map((n) => parseInt(n, 10) || 1);

/** A size kept inside the tile's bounds, never wider than the grid. */
export function clampSize(bounds, cols, c, r) {
    const b = bounds || { min: [1, 1], max: [1, 1] };
    const maxC = Math.min(b.max[0], cols);

    return [Math.max(Math.min(b.min[0], maxC), Math.min(maxC, c)), Math.max(b.min[1], Math.min(b.max[1], r))];
}

/**
 * The resize grip sits in the inline-end corner, so dragging towards the
 * inline end grows the tile: right in English, left in Arabic. The pointer's
 * x movement is mirrored in RTL; y is the same in both.
 */
export const inlineDelta = (dx, rtl) => dx * (rtl ? -1 : 1);

/** Arrow keys on the grip: [columns, rows] to add. Towards the inline end grows. */
export function resizeStep(key, rtl) {
    const grow = rtl ? -1 : 1;

    return { ArrowRight: [grow, 0], ArrowLeft: [-grow, 0], ArrowDown: [0, 1], ArrowUp: [0, -1] }[key] || null;
}

/** Arrow keys on a tile: one place earlier (-1) or later (1), following the reading direction. */
export function moveStep(key, rtl) {
    return { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, ArrowDown: 1, ArrowUp: -1 }[key] ?? null;
}

/**
 * Cell by cell, with a margin around each cell edge: the size changes only once
 * the pointer is clearly into the next cell, so it never flickers between two.
 */
export const stepCells = (raw, cur) => (raw > cur + 0.6 ? Math.floor(raw + 0.4) : raw < cur - 0.6 ? Math.ceil(raw - 0.4) : cur);

/**
 * A tile drawn as an icon: every shortcut, and a widget made smaller than the
 * smallest size it can show itself at (bounds.card). Such a widget opens in a
 * side panel from its icon (media-request 30ca296).
 */
export function iconish(key, size, bounds, cols) {
    if (isApp(key)) return true;
    const card = (bounds[key] || {}).card;
    if (! card) return false;
    const [w, h] = parse(size);

    return Math.min(w, cols) < Math.min(card[0], cols) || h < card[1];
}

/** How wide a closed tile (nothing in it right now) is: one line, at most four cells. */
export const closedSize = (cols) => Math.min(4, cols) + 'x1';

/**
 * Where every visible tile goes, with no holes left beside the cards:
 *  1. place tiles in order, each in the first free spot that fits (like a
 *     phone's home screen, and CSS "dense");
 *  2. a card with empty cells beside it grows into them, then into empty cells
 *     below it, never past its own maximum.
 * Two orders are tried: the person's own, and the same cards with the
 * shortcuts dropped into the gaps between them afterwards. Whichever leaves
 * fewer empty cells wins (the person's order on a tie). Only what is shown
 * changes; the saved sizes stay as the person set them.
 *
 * Tiles with nothing in them right now (`closed`) go last, as one line each,
 * below everything else, and never grow taller (media-request d32e9d7). A tile
 * the person sized by hand (`fixed`) keeps exactly that size (1ce880d). Icons
 * (shortcuts, and widgets made small) are placed, never grown.
 *
 * @param {{key: string, size: string, fixed?: boolean}[]} shown  the visible tiles, in order
 * @param {Set<string>} closed  keys of the tiles shown as one closed line
 * @returns {Object<string, {x: number, y: number, w: number, h: number}>}
 */
export function pack(shown, cols, bounds, closed = new Set()) {
    const visible = shown.map((i) => (closed.has(i.key) ? { ...i, size: closedSize(cols) } : i));
    const full = visible.filter((i) => ! closed.has(i.key));
    const shut = visible.filter((i) => closed.has(i.key));
    const icon = (i) => iconish(i.key, i.size, bounds, cols);
    const own = packOrder([...full, ...shut], cols, bounds, closed);
    const gaps = packOrder([...full.filter((i) => ! icon(i)), ...full.filter(icon), ...shut], cols, bounds, closed);

    return gaps.holes < own.holes ? gaps.out : own.out;
}

export function packOrder(list, cols, bounds, flat = new Set()) {
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
    // Shortcuts, and widgets made so small they show as an icon: placed, never grown.
    const icons = new Set(list.filter((it) => ! flat.has(it.key) && iconish(it.key, it.size, bounds, cols)).map((it) => it.key));
    const fixed = new Set(list.filter((it) => it.fixed).map((it) => it.key));

    // A one-row card followed by shortcuts takes the rest of that row, so the
    // cards after it start on a clean row instead of squeezing in beside it.
    const fillRow = {};
    list.forEach((it, i) => {
        if (icons.has(it.key) || fixed.has(it.key) || flat.has(it.key)) return;
        const [w, h] = parse(it.size);
        if (h !== 1) return;
        let n = 0;
        while (list[i + 1 + n] && icons.has(list[i + 1 + n].key)) n++;
        const max = Math.min((bounds[it.key] || { max: [w, 1] }).max[0], cols);
        // Too many to fit beside it (a phone): it takes the whole row and they go below.
        if (n) fillRow[it.key] = Math.min(max, w + n <= cols ? Math.max(w, cols - n) : cols);
    });

    let floor = null; // the closed tiles start below everything else, not in a gap above
    for (const it of list) {
        let [w, h] = parse(it.size);
        w = Math.min(fillRow[it.key] || w, cols);
        if (flat.has(it.key) && floor === null) floor = used.length;
        let y = flat.has(it.key) ? floor : 0;
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
    // Shortcuts that end a row with empty cells after them move to the row's end,
    // so the card before them can grow into the space instead.
    for (let y = 0; y < rows; y++) {
        let end = cols;
        while (end > 0 && ! (used[y] && used[y][end - 1])) end--;
        const k = cols - end;
        if (! k) continue;
        const run = Object.keys(out)
            .filter((key) => icons.has(key) && out[key].y === y && out[key].x < end)
            .sort((a, b) => out[b].x - out[a].x);
        const moving = [];
        let edge = end;
        for (const key of run) {
            if (out[key].x !== edge - 1) break;
            moving.push(key);
            edge--;
        }
        // Only worth it when a card sits just before them, one row high here, to take the space.
        const before = edge > 0 && Object.keys(out).find((key) => ! icons.has(key) && ! fixed.has(key)
            && out[key].y === y && out[key].h === 1 && out[key].x + out[key].w === edge);
        if (! moving.length || ! before) continue;
        for (const key of moving) {
            used[y][out[key].x] = false;
            out[key].x += k;
            used[y][out[key].x] = true;
        }
    }

    // A tile sized by hand keeps that size; the others grow into the gaps (a closed one only sideways).
    const cards = Object.keys(out).filter((key) => ! icons.has(key) && ! fixed.has(key));
    for (const key of cards) while (grow(key, 1, 0));
    for (const key of cards) if (! flat.has(key)) while (grow(key, 0, 1));

    let holes = 0;
    for (let y = 0; y < rows; y++) for (let x = 0; x < cols; x++) if (! (used[y] && used[y][x])) holes++;

    return { out, holes };
}
