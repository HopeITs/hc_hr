/**
 * Layout for the team staffing chart. Pure: no DOM, so it can be tested on its
 * own (node) with made-up card sizes.
 *
 * Input is a flat list in sibling order: [{ id, parent, w, h }]. Output:
 *   {
 *     nodes:  { [id]: { x, y, w, h, depth } },    // top-left corner, physical px
 *     groups: [{ id, x, y, w, h, depth }],        // one box per expanded team (id = its manager)
 *     links:  [[x1, y1, x2, y2], ...],            // straight connector segments
 *     levels: [{ x0, y0, x1, y1 }, ...],          // bounds of every card and box per depth
 *     width, height, rootId,
 *   }
 *
 * The shape:
 *  - Every expanded manager's direct reports sit together in one team box (a
 *    soft rounded rectangle with padding all round). The manager is centred over
 *    its box; one line drops from the manager to the box's top edge, runs along
 *    that edge, and drops a padding's length to each report in the box's first
 *    row. Who belongs to which team is read from the boxes, not from lines.
 *  - Levels are global: every box of one depth starts on one line, below the
 *    deepest box of the level above plus a fixed gap. A box is as tall as its
 *    own team, so a short team does not grow to match a tall one.
 *  - A team with many individual contributors stacks them in a compact grid
 *    (2 to 4 columns, by count) beside the managers of the same team.
 *  - Horizontal placement is contour based (Reingold–Tilford style): each
 *    subtree is reduced to one extent per level, the parts of a team are packed
 *    left to right as close as those extents allow (a small gap between cards
 *    of one team, a larger one between neighbouring team boxes), then centred
 *    under their manager. Team boxes are the extents of their level, so the
 *    padding is part of the separation: boxes never overlap each other or a
 *    card that is not theirs.
 *  - RTL mirrors x, so the first report (the first manager) sits on the right.
 */

export const DEFAULTS = Object.freeze({
    siblingGap: 16, // between two cards (or a card and a grid) of one team
    subtreeGap: 24, // between two neighbouring team boxes
    levelGap: 40, // between the lowest box of a level and the boxes below it
    groupPad: 14, // inside a team box, on every side
    gridColGap: 16,
    gridRowGap: 16,
    // A team with at least this many individual contributors stacks them in a grid.
    // Two is enough: a row of side-by-side leaves is what made the tree several
    // screens wide, while a short column under the manager costs only height.
    leafGroupMin: 2,
    rtl: false,
});

/** Grid columns for a block of n leaves: one column up to three, then 2 to 4. */
export function gridColumns(n) {
    if (n <= 3) return 1;
    if (n <= 8) return 2;
    return n <= 15 ? 3 : 4;
}

const span = (a, b) => {
    if (!a) return b ? [b[0], b[1]] : null;
    if (!b) return [a[0], a[1]];
    return [Math.min(a[0], b[0]), Math.max(a[1], b[1])];
};

export function layoutTree(input, options = {}) {
    const o = { ...DEFAULTS, ...options };
    const pad = o.groupPad;

    // ---- Build the tree --------------------------------------------------
    const byId = new Map();
    input.forEach((n) => {
        const id = String(n.id);
        if (byId.has(id)) return;
        byId.set(id, {
            id,
            parent: n.parent === null || n.parent === undefined || n.parent === '' ? null : String(n.parent),
            w: Math.max(0, Number(n.w) || 0),
            h: Math.max(0, Number(n.h) || 0),
            children: [],
            depth: 0,
        });
    });

    const roots = [];
    for (const n of byId.values()) {
        const p = n.parent !== null ? byId.get(n.parent) : null;
        if (p && p !== n) p.children.push(n);
        else roots.push(n);
    }

    const empty = { nodes: {}, groups: [], links: [], levels: [], width: 0, height: 0, rootId: null };
    if (roots.length === 0) return empty;

    // Several roots (should not happen) hang under an invisible, zero-size
    // node; it draws no card, no box and no lines, and its "team" is level 0.
    const top = roots.length === 1
        ? roots[0]
        : { id: null, virtual: true, w: 0, h: 0, children: roots, depth: -1 };

    // Depths, top-down; anything unreachable (a cycle) is dropped.
    const reached = [];
    const stack = [[top, top.virtual ? -1 : 0]];
    const seen = new Set();
    while (stack.length) {
        const [n, d] = stack.pop();
        if (seen.has(n)) continue;
        seen.add(n);
        n.depth = d;
        if (!n.virtual) reached.push(n);
        for (let i = n.children.length - 1; i >= 0; i--) stack.push([n.children[i], d + 1]);
    }
    if (reached.length === 0) return empty;

    // ---- Teams -------------------------------------------------------------
    // A team is the row of one manager's reports: managers as subtrees, and the
    // individual contributors either one by one or, when many, as one grid.
    const teams = [];
    for (const n of [top, ...reached]) {
        n.children = n.children.filter((c) => seen.has(c));
        if (n.children.length === 0) continue;

        const managers = n.children.filter((c) => c.children.length > 0);
        const leaves = n.children.filter((c) => c.children.length === 0);
        const team = { owner: n, depth: n.depth + 1, boxed: !n.virtual, units: [] };

        if (leaves.length >= o.leafGroupMin) {
            const cols = Math.min(gridColumns(leaves.length), leaves.length);
            const rows = [];
            for (let i = 0; i < leaves.length; i += cols) rows.push(leaves.slice(i, i + cols));
            const colW = Math.max(...leaves.map((l) => l.w));
            const grid = { isGrid: true, cols, rows, leaves, colW };
            leaves.forEach((l, i) => {
                l.grid = grid;
                l.gridRow = Math.floor(i / cols);
                l.gridCol = i % cols;
            });
            grid.rowHeights = rows.map((row) => Math.max(...row.map((l) => l.h)));
            grid.rowOffsets = [];
            let y = 0;
            grid.rowHeights.forEach((h) => {
                grid.rowOffsets.push(y);
                y += h + o.gridRowGap;
            });
            grid.h = y - o.gridRowGap;
            const total = cols * colW + (cols - 1) * o.gridColGap;
            grid.colCenter = (c) => -total / 2 + c * (colW + o.gridColGap) + colW / 2;
            grid.x0 = -total / 2;
            grid.x1 = total / 2;
            team.units = [...managers, grid];
        } else {
            team.units = [...n.children];
        }

        team.contentH = Math.max(...team.units.map((u) => u.h));
        n.team = team;
        teams.push(team);
    }

    // ---- Levels: one line per depth ---------------------------------------
    const maxDepth = Math.max(...reached.map((n) => n.depth));
    const contentH = [];
    const boxed = [];
    for (const n of reached) {
        if (n.depth === 0 && !top.virtual) contentH[0] = Math.max(contentH[0] || 0, n.h);
    }
    for (const t of teams) {
        contentH[t.depth] = Math.max(contentH[t.depth] || 0, t.contentH);
        boxed[t.depth] = boxed[t.depth] || t.boxed;
    }
    const rowTop = [];
    const boxTop = [];
    for (let k = 0; k <= maxDepth; k++) {
        if (k === 0) {
            boxTop[0] = 0;
            rowTop[0] = 0;
            continue;
        }
        const above = rowTop[k - 1] + (contentH[k - 1] || 0) + (boxed[k - 1] ? pad : 0);
        boxTop[k] = above + o.levelGap;
        rowTop[k] = boxTop[k] + (boxed[k] ? pad : 0);
    }

    // ---- Horizontal pass (bottom-up) --------------------------------------
    // A contour is an array of [x0, x1] per level, relative to the unit's
    // centre; index 0 is the unit's own level. For a manager: its card, then
    // its team box, then everything below.
    const contourOf = (n) => {
        const own = n.virtual ? null : [-n.w / 2, n.w / 2];
        const t = n.team;
        if (!t) return [own];

        const parts = t.units.map((u) => (u.isGrid ? [[u.x0, u.x1]] : contourOf(u)));
        const offsets = [];
        const acc = [];
        parts.forEach((c, i) => {
            let off = 0;
            if (i > 0) {
                off = -Infinity;
                for (let l = 0; l < c.length && l < acc.length; l++) {
                    if (!c[l] || !acc[l]) continue;
                    off = Math.max(off, acc[l][1] - c[l][0] + (l === 0 ? o.siblingGap : o.subtreeGap));
                }
            }
            offsets.push(off);
            c.forEach((e, l) => {
                if (e) acc[l] = span(acc[l], [e[0] + off, e[1] + off]);
            });
        });

        // Centre the team (its cards, not the deeper levels) under the manager.
        const center = (acc[0][0] + acc[0][1]) / 2;
        t.units.forEach((u, i) => {
            u.rel = offsets[i] - center;
        });
        t.rel0 = acc[0][0] - center - (t.boxed ? pad : 0);
        t.rel1 = acc[0][1] - center + (t.boxed ? pad : 0);

        const out = [own, [t.rel0, t.rel1]];
        for (let l = 1; l < acc.length; l++) {
            out[l + 1] = acc[l] ? [acc[l][0] - center, acc[l][1] - center] : null;
        }
        return out;
    };

    contourOf(top);

    // ---- Absolute pass ----------------------------------------------------
    const pos = new Map();
    const groups = [];
    const links = [];

    const place = (n, cx) => {
        if (!n.virtual) {
            const y = n.grid ? rowTop[n.depth] + n.grid.rowOffsets[n.gridRow] : rowTop[n.depth];
            pos.set(n, { x: cx - n.w / 2, y, w: n.w, h: n.h, depth: n.depth });
        }

        const t = n.team;
        if (!t) return;

        const drops = [];
        for (const u of t.units) {
            if (u.isGrid) {
                for (const l of u.leaves) {
                    const lcx = cx + u.rel + u.colCenter(l.gridCol);
                    place(l, lcx);
                    if (l.gridRow === 0) drops.push(lcx);
                }
            } else {
                place(u, cx + u.rel);
                drops.push(cx + u.rel);
            }
        }

        if (!t.boxed) return;

        const y0 = boxTop[t.depth];
        groups.push({
            id: n.id,
            x: cx + t.rel0,
            y: y0,
            w: t.rel1 - t.rel0,
            h: t.contentH + pad * 2,
            depth: t.depth,
        });

        const me = pos.get(n);
        links.push([cx, me.y + me.h, cx, y0]);
        const lo = Math.min(cx, ...drops);
        const hi = Math.max(cx, ...drops);
        if (hi > lo) links.push([lo, y0, hi, y0]);
        drops.forEach((x) => links.push([x, y0, x, rowTop[t.depth]]));
    };

    place(top, 0);

    // ---- Normalise to (0, 0), round, mirror for RTL ------------------------
    let minX = Infinity;
    let maxX = -Infinity;
    let maxY = 0;
    const grow = (x0, x1, y1) => {
        minX = Math.min(minX, x0);
        maxX = Math.max(maxX, x1);
        maxY = Math.max(maxY, y1);
    };
    for (const p of pos.values()) grow(p.x, p.x + p.w, p.y + p.h);
    for (const g of groups) grow(g.x, g.x + g.w, g.y + g.h);
    for (const [x1, , x2, y2] of links) grow(Math.min(x1, x2), Math.max(x1, x2), y2);

    const width = Math.ceil(maxX - minX);
    const height = Math.ceil(maxY);
    // A box of width w whose left edge is at x (pre-normalisation).
    const fx = (x, w = 0) => {
        const v = Math.round(x - minX);
        return o.rtl ? width - v - Math.round(w) : v;
    };

    const nodes = {};
    for (const [n, p] of pos) {
        nodes[n.id] = { x: fx(p.x, p.w), y: Math.round(p.y), w: p.w, h: p.h, depth: p.depth };
    }

    const outGroups = groups.map((g) => ({
        id: g.id,
        x: fx(g.x, g.w),
        y: Math.round(g.y),
        w: Math.round(g.w),
        h: Math.round(g.h),
        depth: g.depth,
    }));

    const levels = [];
    const addLevel = (d, x, y, w, h) => {
        const l = levels[d] || (levels[d] = { x0: Infinity, y0: Infinity, x1: -Infinity, y1: -Infinity });
        l.x0 = Math.min(l.x0, x);
        l.y0 = Math.min(l.y0, y);
        l.x1 = Math.max(l.x1, x + w);
        l.y1 = Math.max(l.y1, y + h);
    };
    for (const p of Object.values(nodes)) addLevel(p.depth, p.x, p.y, p.w, p.h);
    for (const g of outGroups) addLevel(g.depth, g.x, g.y, g.w, g.h);
    for (let d = 0; d < levels.length; d++) {
        if (!levels[d]) levels[d] = d > 0 ? { ...levels[d - 1] } : { x0: 0, y0: 0, x1: 0, y1: 0 };
    }

    return {
        nodes,
        groups: outGroups,
        links: links.map(([x1, y1, x2, y2]) => [fx(x1), Math.round(y1), fx(x2), Math.round(y2)]),
        levels,
        width,
        height,
        rootId: top.virtual ? (roots[0]?.id ?? null) : top.id,
    };
}
