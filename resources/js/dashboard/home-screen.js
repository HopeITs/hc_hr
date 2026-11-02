/*
 * The dashboard as a home screen, phone style (ported from media-request's
 * public/js/home-screen.js). Loaded on the dashboard only: its own Vite entry,
 * included by livewire/dashboard/index.blade.php.
 *
 * A grid of cells (12 columns on a wide screen, 8 on a computer, 4 on a phone;
 * resources/css/dashboard.css). Each tile is C×R cells. Tiles are moved and
 * resized where they are, with no mode to enter first (the feel of
 * hc-tracking's Desk, resources/js/desk-grid.js). What the person does happens
 * in the browser straight away:
 *
 *   - move: a mouse picks a tile up after a few pixels of travel; a finger or
 *     a pen picks it up after a 400ms press and hold, so a swipe still
 *     scrolls. The tile follows the pointer, and the others slide out of the
 *     way to where they will be, so what is seen is where it lands. Near the
 *     top or bottom of the window the page scrolls. Escape puts everything
 *     back. Links and buttons in a card are not handles, and the click that
 *     ends a real move never opens anything. A pinned tile stays first;
 *   - resize: pull any edge of a tile, or its inline-end corner (on a
 *     computer, not a phone). It snaps cell by cell between the tile's own
 *     minimum and maximum, with a "2×3" label while pulling. Pulling the top
 *     edge scrolls the page with it, so the bottom edge stays put. Escape puts
 *     the old size back;
 *   - × hides a tile, with Undo for a few seconds; Add brings one back later;
 *   - "Customise" shows the faint grid of the cells, the pin and look
 *     buttons, Add and Reset, and lets the keyboard move a focused tile (arrow
 *     keys) and resize it (arrow keys on its grip). A click on empty space (not
 *     a tile, a control or a sheet) ends it.
 *
 * The first move or resize at rest starts from exactly what is on screen
 * (bake()), so nothing jumps; tiles with nothing in them and icons keep their
 * state.
 *
 * At rest (ported from media-request, 2026-10-29):
 *
 *   - a tile with nothing in it right now (its card marks its empty state
 *     with data-tile-empty, data-state="empty" or .hc-nt-empty) goes to the
 *     bottom as one closed line; Customise shows it whole;
 *   - a tile made smaller than its card can show (bounds.card) is an icon:
 *     one click opens the card in a steady side panel (a sheet from the bottom
 *     on a phone), a double-click opens its page. The panel is the card
 *     itself as a popover (the browser's top layer), so its Livewire
 *     component is never moved or re-mounted; ×, Escape or the same icon
 *     closes it and focus goes back to the icon;
 *   - a shortcut made big shows its data (AppCard); on a computer one click
 *     on a shortcut shows that card in the panel, a double-click opens the
 *     page; on a phone a tap opens the page;
 *   - a double-click on a widget that has a page (data-page) opens it;
 *   - the wheel stays in a card only where there is more to scroll there;
 *   - a phone does not draw the heavy tiles (PHONE_HIDDEN).
 *
 * Tiles always sit exactly where they will be saved: the packed places, live
 * while carrying or pulling; a size set by hand is kept as set (fixed).
 *
 * Changes glide into place (FLIP) unless the person prefers less motion. The
 * layout is saved in the background ($wire.saveLayout is renderless), one
 * request at a time so an older save can never land after a newer one. Two
 * things do re-render the page: Add, for a tile the page never drew (hidden
 * tiles are not rendered at all), and Reset.
 *
 * Used as x-data="hcHomeScreen" with the layout in data-home (the server
 * keeps that attribute current, so after Reset the new layout is read back).
 */

import { bakedSize, clampSize, dropsAfter, edgeGrowth, iconish, inlineDelta, isApp, moveStep, pack, parse, phoneView, rawCells, reorder, resizeStep, stepCells } from './layout-math.js';
// The clock tile's x-data (hcClock), on the dashboard only, like this script.
import './clock.js';

const EASE = 'cubic-bezier(.2,.8,.2,1)';
// Tiles gliding to a new place overshoot a touch, like the Desk's panels.
const GLIDE = 'cubic-bezier(.2,.9,.25,1.12)';
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const fill = (text, values) => Object.entries(values).reduce((out, [name, value]) => out.replaceAll(':' + name, value), text || '');

// Picking a tile up: a mouse after this much travel, a finger or a pen after this long a hold.
const MOVE_THRESHOLD = 6;
const HOLD_DELAY = 400;
const HOLD_SLOP = 10; // a finger that travels this far before the hold is scrolling
const SCROLL_EDGE = 72; // the page scrolls while a tile is carried this close to the window's top or bottom
const UNDO_FOR = 6000;
// A press on these is the control's own, never the start of a move.
const NOT_HANDLE = 'a, button, input, select, textarea, label, [contenteditable], [role="menu"], [data-resize], .hc-grip, .hc-tbtn';
// ...except a tile's own face: a shortcut's link and a small widget's icon are the whole tile.
const FACE = '.hc-app, .hc-wmini';
const narrowScreen = () => window.matchMedia('(max-width: 760px)').matches;

// ":popover-open" where the browser knows it (an unknown selector would throw in closest()).
const OPEN = (() => {
    try {
        return CSS.supports('selector(:popover-open)') ? ':popover-open' : '.hc-popover-open';
    } catch (e) {
        return '.hc-popover-open';
    }
})();
// What a card marks as "nothing here right now"; a loading placeholder is never empty.
const EMPTY_MARK = '[data-tile-empty], [data-state="empty"], .hc-nt-empty';
// A press or a click on these is not "outside" while customising.
const NOT_OUTSIDE = '.hc-tile, button, a, input, select, textarea, label, dialog, [role="dialog"], .hc-sheet-back, [data-slot="bottom-bar-picker"], ' + OPEN;
// Elements in a card that may scroll on their own.
const SCROLLERS = '.hc-tile-body, .hc-tile-body [class*="overflow-"], .hc-bdays-list, .hc-decide';

const navigateTo = (url, external = false) => {
    if (external) window.open(url, '_blank', 'noopener');
    else if (window.Livewire?.navigate) window.Livewire.navigate(url);
    else window.location.assign(url);
};

// ?customise=1 (the command palette) opened the page in edit mode; a reload should not.
function dropCustomiseParam() {
    const url = new URL(location.href);
    if (! url.searchParams.has('customise')) return;
    url.searchParams.delete('customise');
    history.replaceState(history.state, '', url.pathname + url.search + url.hash);
}

// What scrolls the grid: the nearest scrolling ancestor, else the window.
function scrollerOf(el) {
    for (let n = el?.parentElement; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
        const y = getComputedStyle(n).overflowY;
        if ((y === 'auto' || y === 'scroll' || y === 'overlay') && n.scrollHeight > n.clientHeight + 1) return n;
    }

    return window;
}

function homeScreen() {
    // Kept outside Alpine's reactive state.
    const glides = new WeakMap(); // glide running, per tile element
    const cleanup = [];
    let carry = null; // the tile being moved (pressed, or picked up: carry.active)
    let pull = null; // the tile being resized
    let swallowNext = false; // the click that ends a real move or resize opens nothing
    let undoTimer = null;
    let undoState = null; // where a tile was before × hid it: { key, index, pinned }
    let saveTimer = null;
    let inflight = null; // the save on its way to the server
    let saveAgain = false; // something changed while it was
    let adopting = false; // Reset: take the layout the server sends back
    let arriving = null; // Add: the tile the server is about to draw
    let packCache = { sig: null, value: null };
    let clickTimer = null; // one click on an icon waits to see if a second follows
    let overTile = null; // the tile under the pointer (scroll check)
    let downOutside = false; // the press that may end customising started outside

    return {
        items: [],
        absent: [],
        bounds: {},
        looks: {},
        labels: {},
        text: {},
        cols: 8,
        editing: false,
        gallery: false,
        carrying: false, // a tile is picked up and follows the pointer
        resizing: null, // key of the tile being resized
        resizeLabel: '',
        removed: null, // { key, label } of the tile × just hid, while Undo is offered
        announcement: '',
        phoneHidden: [],
        empty: {}, // key => the card's own "nothing here" words, for tiles with nothing in them now
        peek: null, // key of the tile whose card is open in the side panel

        init() {
            const config = this.configure();

            const onResize = () => {
                this.readCols();
                this.drawGuides();
            };
            window.addEventListener('resize', onResize);
            cleanup.push(() => window.removeEventListener('resize', onResize));

            // Not before the next tick: the x-ref children are not set up yet during
            // init(), and Alpine keeps the first $refs it hands out.
            this.$nextTick(() => {
                this.readCols();

                // The grid's own width decides 12, 8 or 4 columns (a container query), so watch it too.
                if (window.ResizeObserver) {
                    const observer = new ResizeObserver(onResize);
                    observer.observe(this.$refs.grid.parentElement);
                    cleanup.push(() => observer.disconnect());
                }

                this.watchPointer();

                if (config.editing) {
                    dropCustomiseParam();
                    this.startEdit();
                }
            });

            // Leaving the page: send what is still waiting.
            const onNavigating = () => this.flushSave();
            document.addEventListener('livewire:navigating', onNavigating);
            cleanup.push(() => document.removeEventListener('livewire:navigating', onNavigating));

            // The page re-rendered (Add, Reset, a retry): keep the person's order on screen.
            this.$wire.$hook('morphed', () => this.$nextTick(() => this.afterServerRender()));

            this.watchFailures();
            this.$nextTick(() => {
                this.watchEmpty();
                this.watchScroll();
            });
            this.watchOutside();

            this.$watch('editing', (on) => {
                if (on) this.closePeek(false);
                this.$nextTick(() => this.drawGuides());
            });
        },

        destroy() {
            this.endCarry(false);
            this.endPull(false);
            cleanup.forEach((fn) => fn());
            clearTimeout(clickTimer);
            clearTimeout(undoTimer);
            this.closePeek(false);
        },

        // ---- the layout ---------------------------------------------------
        // data-home: the layout (hidden tiles included), each tile's bounds,
        // looks and name, the tiles not drawn (absent) and the words to say.
        configure() {
            let config = {};
            try {
                config = JSON.parse(this.$root.dataset.home || '{}');
            } catch (e) {
                return config;
            }
            this.absent = config.absent || [];
            this.bounds = config.bounds || {};
            this.looks = Array.isArray(config.looks) ? {} : config.looks || {};
            this.labels = config.labels || {};
            this.text = config.text || {};
            this.phoneHidden = config.phoneHidden || [];
            if (adopting || ! this.items.length) this.items = config.layout || [];
            adopting = false;

            return config;
        },

        afterServerRender() {
            if (! this.$refs.grid?.isConnected) return;
            this.configure();
            this.restoreOrder();
            this.readCols();
            this.drawGuides();
            this.readEmpty();

            if (arriving) {
                const el = this.tileEl(arriving);
                if (el) {
                    if (! reducedMotion()) el.animate([{ opacity: 0, transform: 'scale(.9)' }, { opacity: 1, transform: 'none' }], { duration: 220, easing: EASE });
                    el.scrollIntoView({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
                    this.announce(this.text.added, { tile: this.labelOf(arriving) });
                }
                arriving = null;
            }
        },

        // The server draws tiles in the saved order; the person may have moved
        // some since (or entered editing, which sorts them as they are seen).
        restoreOrder() {
            const grid = this.$refs.grid;
            const want = this.items.map((it) => this.tileEl(it.key)).filter(Boolean);
            const now = this.tiles();
            if (want.length === now.length && want.every((el, i) => el === now[i])) return;
            want.forEach((el) => grid.appendChild(el));
        },

        // ---- grid ---------------------------------------------------------
        // The column count comes from the CSS (12, 8 or 4). Read the variable
        // itself: counting drawn columns is wrong once tiles are placed by
        // number, because a tile past the last column makes the browser add columns.
        readCols() {
            if (! this.$refs.grid?.isConnected) return;
            const n = parseInt(getComputedStyle(this.$refs.grid).getPropertyValue('--cols'), 10);
            if (n > 0 && n !== this.cols) this.cols = n;
        },
        metrics() {
            const grid = this.$refs.grid;
            const cs = getComputedStyle(grid);
            const gap = parseFloat(cs.columnGap) || 0;
            const rowH = parseFloat(cs.gridAutoRows) || 100;
            const colW = (grid.clientWidth - gap * (this.cols - 1)) / this.cols;

            return { gap, rowH, colW, rtl: cs.direction === 'rtl' };
        },
        // The faint cells shown while editing, drawn behind the tiles in the same grid.
        drawGuides() {
            const g = this.$refs.guides;
            if (! g || ! this.$refs.grid?.isConnected) return;
            if (! this.editing) {
                g.replaceChildren();
                return;
            }
            const { gap, rowH } = this.metrics();
            const rows = Math.ceil((this.$refs.grid.scrollHeight + gap) / (rowH + gap)) + 1;
            const want = rows * this.cols;
            if (g.childElementCount !== want) {
                g.replaceChildren(...Array.from({ length: want }, () => document.createElement('i')));
            }
        },
        tiles() {
            return [...this.$refs.grid.querySelectorAll(':scope > .hc-tile')];
        },
        tileEl(key) {
            return this.tiles().find((el) => el.dataset.key === key) || null;
        },

        // ---- state helpers used by the template ---------------------------
        item(key) {
            return this.items.find((i) => i.key === key) || { key, size: '1x1', hidden: true, pinned: false };
        },
        hidden(key) {
            return this.item(key).hidden;
        },
        // A phone (4 columns) keeps its home screen light: no slideshow. The saved layout is not changed.
        phone() {
            return this.cols <= 4;
        },
        shownHere(key) {
            return ! this.hidden(key) && ! (this.phone() && this.phoneHidden.includes(key));
        },
        // Nothing in it right now: one closed line at the bottom (never while customising, never when pinned).
        // A pinned tile stays where it was pinned and shows its own empty state.
        closed(key) {
            return ! this.editing && this.empty[key] !== undefined && ! isApp(key) && ! this.pinned(key);
        },
        closedText(key) {
            return this.empty[key] || this.text.nothing || '';
        },
        // Made smaller than its card can show: an icon that opens the card in the side panel.
        mini(key) {
            if (isApp(key) || this.closed(key)) return false;
            const p = this.packed[key];

            return iconish(key, p ? p.w + 'x' + p.h : this.item(key).size, this.bounds, this.cols);
        },
        pinned(key) {
            return this.item(key).pinned;
        },
        // What Add offers: hidden tiles the page can draw.
        addable(kind = null) {
            return this.items.filter((i) => i.hidden && ! this.absent.includes(i.key)
                && (kind === null || (kind === 'app') === isApp(i.key)));
        },
        labelOf(key) {
            return this.labels[key] || key;
        },
        /*
         * Each tile gets its packed place (gaps filled), while customising too:
         * what is seen is what is saved, and a carried tile reorders the list at
         * once (reflow()) so the others glide to where they will end up.
         * A tile is never wider than the grid. An object, so only these two
         * properties are set and x-show keeps control of display.
         */
        span(key) {
            const p = this.packed[key];
            if (p) return { gridColumn: (p.x + 1) + ' / span ' + p.w, gridRow: (p.y + 1) + ' / span ' + p.h };
            const [c, r] = parse(this.item(key).size);

            return { gridColumn: 'span ' + Math.min(c, this.cols), gridRow: 'span ' + r };
        },
        // The size the tile is shown at, in cells (data-cols / data-rows on the tile).
        shown(key) {
            const p = this.packed[key];
            if (p) return [p.w, p.h];
            const [c, r] = parse(this.item(key).size);

            return [Math.min(c, this.cols), r];
        },
        // Where every visible tile goes at rest, gaps filled (layout-math.js pack()).
        get packed() {
            const shown = this.items.filter((i) => this.shownHere(i.key) && ! this.absent.includes(i.key));
            // Tiles with nothing in them go last, closed (the saved order is not changed); customising shows them whole.
            const closed = new Set(this.editing ? [] : shown.filter((i) => this.closed(i.key)).map((i) => i.key));
            // A phone shows the clock one row high (phoneView): the packer is told, so nothing is left empty under it.
            const view = phoneView(shown, this.bounds, this.cols, this.editing);
            const sig = this.cols + '|' + (this.editing ? 'e' : '') + '|' + [...closed].join(',') + '|' + view.shown.map((i) => i.key + ':' + i.size + (i.fixed ? '!' : '')).join(',');
            if (packCache.sig !== sig) packCache = { sig, value: pack(view.shown, this.cols, view.bounds, closed) };

            return packCache.value;
        },
        // Tiles with more than one look (the clock: digital or analogue).
        styleOf(key) {
            return this.item(key).style || (this.looks[key] || [])[0] || '';
        },
        nextLook(key) {
            const list = this.looks[key] || [];

            return list[(list.indexOf(this.styleOf(key)) + 1) % (list.length || 1)] || '';
        },
        cycleStyle(key) {
            if ((this.looks[key] || []).length < 2) return;
            this.item(key).style = this.nextLook(key);
            this.save();
        },
        sizeText(key) {
            const [c, r] = this.shown(key);

            return c + '×' + r;
        },
        sizeWords(key) {
            const [c, r] = this.shown(key);

            return fill(this.text.size, { cols: c, rows: r });
        },
        // In edit mode a tile is focusable: its name, size and place.
        tileLabel(key) {
            const shown = this.tiles().filter((el) => el.offsetParent !== null);
            const el = this.tileEl(key);

            return fill(this.text.tile, { tile: this.labelOf(key), size: this.sizeWords(key), n: shown.indexOf(el) + 1, total: shown.length });
        },
        resizeLabelFor(key) {
            return fill(this.text.resize, { tile: this.labelOf(key), size: this.sizeWords(key) });
        },
        announce(template, values) {
            this.announcement = '';
            this.$nextTick(() => {
                this.announcement = fill(template, values);
            });
        },

        // ---- editing ------------------------------------------------------
        startEdit() {
            if (this.editing) return;
            this.flip(() => {
                this.bake();
                this.editing = true;
            });
            // "Customise" is gone: focus moves to Done.
            this.$nextTick(() => this.$refs.done?.focus({ preventScroll: true }));
        },
        /*
         * Editing starts from exactly what is on screen: each tile takes the
         * size and place it is shown at (after packing and gap filling), and the
         * order becomes the on-screen order. Nothing jumps when "Customise" is
         * pressed, and what the person then moves or resizes is what they saw.
         * Saved only once they change something.
         *
         * At rest (the first move or resize, no "Customise") a closed tile keeps
         * its place in the order: it is drawn last only while it has nothing in
         * it, and goes back to where the person put it once it has.
         */
        bake(atRest = false) {
            const p = this.packed;
            const at = (i) => (p[i.key] ? p[i.key].y * 1000 + p[i.key].x : 1e9);
            const byPlace = (a, b) => (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0) || at(a) - at(b);
            if (! atRest) {
                this.items.sort(byPlace);
            } else {
                const keep = (it) => this.closed(it.key);
                const moving = this.items.filter((it) => ! keep(it)).sort(byPlace);
                let n = 0;
                this.items = this.items.map((it) => (keep(it) ? it : moving[n++]));
            }
            for (const it of this.items) {
                // A closed tile keeps its own size: customising shows it whole. A size
                // that only comes from this screen's width is not taken (bakedSize).
                if (p[it.key] && ! it.fixed && ! this.closed(it.key)) it.size = bakedSize(it.size, p[it.key], this.cols);
            }
            this.restoreOrder();
        },
        // Before a move or a resize at rest: the same, without customising. The
        // tile being handled does not glide (it follows the pointer).
        bakeAtRest(key) {
            if (this.editing) return;
            this.flip(() => this.bake(true), key);
        },
        done() {
            if (! this.editing) return;
            this.flip(() => {
                this.editing = false;
            });
            this.gallery = false;
            this.flushSave();
            this.$nextTick(() => this.$refs.customise?.focus({ preventScroll: true }));
        },
        // Escape leaves editing (Add's own Escape closes Add first; the confirm dialog keeps its own).
        escape(e) {
            if (e.defaultPrevented || this.gallery || this.$store.confirm?.open) return;
            if (this.peek) {
                this.closePeek();

                return;
            }
            this.done();
        },
        // Customising ends with a click on empty space. Both the press and the
        // click must be outside, so ending a drag or a resize never counts.
        watchOutside() {
            const outside = (t) => t instanceof Element && t.isConnected && ! t.closest(NOT_OUTSIDE);
            const down = (e) => {
                downOutside = this.editing && outside(e.target);
            };
            const click = (e) => {
                if (this.editing && downOutside && outside(e.target) && ! this.gallery && ! this.$store.confirm?.open) this.done();
                downOutside = false;
            };
            window.addEventListener('pointerdown', down);
            window.addEventListener('click', click);
            cleanup.push(() => {
                window.removeEventListener('pointerdown', down);
                window.removeEventListener('click', click);
            });
        },

        // The click that ends a real move or resize must not also open the link
        // or the panel under the pointer (the grid listens in the capture phase).
        swallowClick(e) {
            if (swallowNext) {
                e.preventDefault();
                e.stopPropagation();
                swallowNext = false;
            } else if (this.appClick(e)) {
                // A shortcut: its card in the panel, or its page (appClick).
            } else if (this.editing && e.target.closest('a') && ! e.target.closest('.hc-tbtn, .hc-grip')) {
                e.preventDefault();
            }
        },

        /*
         * × hides a tile, at rest or while customising. For a few seconds Undo
         * puts it back exactly where it was (pinned again if it was); after
         * that, Add is where it comes back from.
         */
        remove(key) {
            const index = this.items.findIndex((x) => x.key === key);
            if (index < 0) return;
            const it = this.items[index];
            const shown = this.tiles().filter((t) => t.offsetParent !== null);
            const next = shown[shown.indexOf(this.tileEl(key)) + 1] || null;
            undoState = { key, index, pinned: it.pinned };
            this.flip(() => {
                it.hidden = true;
                it.pinned = false;
            });
            this.announce(this.text.hidden, { tile: this.labelOf(key) });
            this.save();
            this.removed = { key, label: this.labelOf(key) };
            clearTimeout(undoTimer);
            undoTimer = setTimeout(() => this.dismissUndo(), UNDO_FOR);
            // Its controls are gone with it: Add while customising, else the next tile when it takes focus.
            this.$nextTick(() => {
                if (this.editing) this.$refs.add?.focus({ preventScroll: true });
                else if (next && next.tabIndex >= 0) next.focus({ preventScroll: true });
            });
        },
        undoRemove() {
            const state = undoState;
            this.dismissUndo();
            if (! state) return;
            const it = this.items.find((x) => x.key === state.key);
            if (! it || ! it.hidden) return;
            const el = this.tileEl(state.key);
            this.flip(() => {
                const rest = this.items.filter((x) => x !== it);
                rest.splice(Math.min(state.index, rest.length), 0, it);
                it.hidden = false;
                it.pinned = state.pinned;
                this.items = [...rest.filter((i) => i.pinned), ...rest.filter((i) => ! i.pinned)];
                // The element is still on the page (x-show): back to its place among the others.
                if (el) {
                    const after = this.items.slice(this.items.indexOf(it) + 1).map((x) => this.tileEl(x.key)).find(Boolean);
                    if (after) after.before(el);
                    else this.$refs.grid.appendChild(el);
                }
            });
            if (! el) {
                // The page was drawn again since (Add, Reset): the server draws it now.
                arriving = state.key;
                this.settle().then(() => this.$wire.addTile(this.payload()));

                return;
            }
            this.save();
            this.announce(this.text.added, { tile: this.labelOf(state.key) });
        },
        dismissUndo() {
            clearTimeout(undoTimer);
            undoTimer = null;
            undoState = null;
            this.removed = null;
        },
        add(key) {
            const i = this.items.findIndex((x) => x.key === key);
            if (i < 0) return;
            if (this.removed?.key === key) this.dismissUndo();
            const el = this.tileEl(key);
            this.flip(() => {
                const [it] = this.items.splice(i, 1);
                it.hidden = false;
                this.items.push(it);
                if (el) this.$refs.grid.appendChild(el);
            });
            if (! this.addable().length) this.closeGallery();
            if (el) {
                this.save();
                this.announce(this.text.added, { tile: this.labelOf(key) });

                return;
            }
            // Hidden when the page was drawn, so not on the page at all: the server draws it now.
            arriving = key;
            this.settle().then(() => this.$wire.addTile(this.payload()));
        },
        togglePin(key) {
            const it = this.item(key);
            this.flip(() => {
                it.pinned = ! it.pinned;
                const el = this.tileEl(key);
                if (it.pinned && el) this.$refs.grid.prepend(el);
            });
            this.syncOrder();
            this.announce(it.pinned ? this.text.pinned : this.text.unpinned, { tile: this.labelOf(key) });
        },
        setCells(key, c, r) {
            [c, r] = clampSize(this.bounds[key], this.cols, c, r);
            const size = c + 'x' + r;
            if (size === this.item(key).size) return false;
            this.flip(() => {
                this.item(key).size = size;
                this.item(key).fixed = true; // sized by hand: shown exactly so, never grown
            }, key);

            return true;
        },
        openGallery() {
            if (this.addable().length) this.gallery = true;
        },
        closeGallery() {
            this.gallery = false;
            // Add may be disabled now (nothing left to add): then Done.
            this.$nextTick(() => (this.addable().length ? this.$refs.add : this.$refs.done)?.focus({ preventScroll: true }));
        },
        async reset() {
            const yes = await this.$confirm({
                title: this.text.resetTitle,
                message: this.text.resetMessage,
                confirmLabel: this.text.resetConfirm,
                cancelLabel: this.text.cancel,
            });
            if (! yes) return;
            await this.settle();
            adopting = true;
            await this.$wire.resetLayout();
        },

        // ---- the side panel: a small tile's card, or a shortcut's ------------
        /*
         * One click on an icon opens the card in a side panel along the
         * screen's end edge (a sheet from the bottom on a phone); two clicks
         * open its page. The panel is the card itself shown as a popover, so
         * it is not moved in the page and its buttons keep working. It stays
         * put while the person works on the page; its ×, Escape or the same
         * icon closes it, and another icon shows that card in the same place.
         */
        panelOf(key) {
            return this.tileEl(key)?.querySelector(isApp(key) ? ':scope > .hc-tile-body > .hc-appcard' : ':scope > .hc-tile-body') || null;
        },
        // The control that opened the panel, for focus to go back to.
        triggerOf(key) {
            return this.tileEl(key)?.querySelector(':scope > .hc-wmini, :scope > .hc-tile-body > .hc-app') || null;
        },
        // One click opens the panel only once it is clear no second click follows.
        peekSoon(e, key) {
            clearTimeout(clickTimer);
            if (this.editing || e.detail > 1) return;
            clickTimer = setTimeout(() => this.peekOpen(key), e.detail === 0 ? 0 : 240);
        },
        peekOpen(key) {
            const panel = this.panelOf(key);
            if (this.editing || ! panel) return;
            if (! panel.showPopover) {
                // No popovers in this browser: the page instead, when there is one.
                const page = this.tileEl(key)?.dataset.page || this.triggerOf(key)?.href;
                if (page) navigateTo(page);

                return;
            }
            if (this.peek === key) {
                this.closePeek();

                return;
            }
            this.closePeek(false);
            try {
                panel.showPopover();
            } catch (e) {
                return;
            }
            this.peek = key;
            this.$nextTick(() => panel.focus({ preventScroll: true }));
        },
        closePeek(restore = true) {
            const key = this.peek;
            const panel = key && this.panelOf(key);
            this.peek = null;
            try {
                if (panel && panel.matches(OPEN)) panel.hidePopover();
            } catch (e) {
                // Already closed.
            }
            if (restore && key) this.triggerOf(key)?.focus({ preventScroll: true });
        },
        // The second click of a double-click on an icon: its page, else the panel as one click would.
        miniPage(key) {
            clearTimeout(clickTimer);
            if (this.editing) return;
            const tile = this.tileEl(key);
            const link = tile?.querySelector(':scope > .hc-tile-body > a.hc-app');
            const page = tile?.dataset.page || link?.href;
            if (! page) {
                this.peekOpen(key);

                return;
            }
            this.closePeek(false);
            navigateTo(page, link?.target === '_blank');
        },
        // A shortcut with a card, on a computer: one click shows its card in the
        // panel (a new-tab or modified click still opens the page). Its link has
        // no wire:navigate (that follows the link as the button comes up), so
        // the page is opened here: at once on a phone, or with no card.
        appClick(e) {
            const link = e.target instanceof Element && e.target.closest('.hc-tile.is-app > .hc-tile-body > a.hc-app');
            if (! link || this.editing || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button > 0) return false;
            const key = link.closest('.hc-tile').dataset.key;
            e.preventDefault();
            e.stopPropagation();
            if (this.phone() || ! link.hasAttribute('aria-haspopup')) {
                clearTimeout(clickTimer);
                navigateTo(link.href, link.target === '_blank');
            } else {
                this.peekSoon(e, key);
            }

            return true;
        },
        // A double-click on a widget that has a page opens it (not while
        // customising, nor on its own controls, nor inside its open panel).
        openPage(e, key) {
            if (this.editing || this.mini(key) || ! (e.target instanceof Element)) return;
            if (e.target.closest('input, textarea, select, button, a, label, [contenteditable], ' + OPEN)) return;
            const page = this.tileEl(key)?.dataset.page;
            if (! page) return;
            try {
                window.getSelection()?.removeAllRanges();
            } catch (err) {
                // Nothing selected.
            }
            navigateTo(page);
        },

        // ---- tiles with nothing in them ----------------------------------------
        // A card marks its own empty state; the grid reads it whenever a card
        // arrives or changes (lazy load, polling, a retry).
        watchEmpty() {
            const grid = this.$refs.grid;
            if (! grid || ! window.MutationObserver) return;
            let frame = null;
            const observer = new MutationObserver(() => {
                if (frame) return;
                frame = requestAnimationFrame(() => {
                    frame = null;
                    this.readEmpty();
                });
            });
            observer.observe(grid, { childList: true, subtree: true });
            cleanup.push(() => observer.disconnect());
            this.readEmpty();
        },
        readEmpty() {
            if (! this.$refs.grid?.isConnected) return;
            const next = {};
            for (const tile of this.tiles()) {
                const key = tile.dataset.key;
                const body = tile.querySelector(':scope > .hc-tile-body');
                if (! key || isApp(key) || ! body || body.querySelector('[data-state="loading"]')) continue;
                const mark = body.querySelector(EMPTY_MARK);
                if (! mark) continue;
                const words = (mark.querySelector('h2, h3') || mark).textContent.replace(/\s+/g, ' ').trim();
                next[key] = words.length <= 80 ? words : '';
            }
            const same = Object.keys(next).length === Object.keys(this.empty).length
                && Object.entries(next).every(([k, v]) => this.empty[k] === v);
            if (same) return;
            // An open panel whose tile closes now stays open: it is the card itself.
            this.flip(() => {
                this.empty = next;
            });
        },

        // ---- the wheel: a card keeps it only where there is more to scroll ----
        // Checked as the pointer comes onto a tile, since its content may have changed.
        watchScroll() {
            const grid = this.$refs.grid;
            if (! grid) return;
            const over = (e) => {
                const tile = e.target instanceof Element ? e.target.closest('.hc-tile') : null;
                if (tile === overTile) return;
                overTile = tile;
                if (! tile) return;
                for (const el of tile.querySelectorAll(SCROLLERS)) {
                    el.classList.toggle('hc-scrolls', el.scrollHeight > el.clientHeight + 2 || el.scrollWidth > el.clientWidth + 2);
                }
            };
            grid.addEventListener('pointerover', over);
            cleanup.push(() => grid.removeEventListener('pointerover', over));
        },

        // ---- move with the keyboard: arrows on a focused tile ----------------
        moveKey(e, key) {
            // Earlier or later in the order; left and right follow the reading direction.
            const step = moveStep(e.key, this.metrics().rtl);
            if (step === null && e.key !== 'Home' && e.key !== 'End') return;
            e.preventDefault();
            if (this.pinned(key)) return; // a pinned tile stays first
            const el = this.tileEl(key);
            const movable = this.tiles().filter((t) => t.offsetParent !== null && ! t.classList.contains('pinned'));
            const from = movable.indexOf(el);
            const to = e.key === 'Home' ? 0 : e.key === 'End' ? movable.length - 1 : from + step;
            if (from < 0 || to < 0 || to >= movable.length || to === from) return;
            this.flip(() => (to > from ? movable[to].after(el) : movable[to].before(el)));
            this.syncOrder();
            el.focus({ preventScroll: true });
            const shown = this.tiles().filter((t) => t.offsetParent !== null);
            this.announce(this.text.moved, { tile: this.labelOf(key), n: shown.indexOf(el) + 1, total: shown.length });
        },

        // ---- the pointer: move and resize, at rest or while customising ------
        /*
         * One set of listeners for both (the Desk's engine, desk-grid.js). The
         * grid hears the press: an edge handle in the capture phase (it is the
         * handle's own), anywhere else on a tile in the bubble phase. The window
         * hears the rest, so a pointer that leaves the grid keeps working.
         */
        watchPointer() {
            const grid = this.$refs.grid;
            if (! grid) return;
            const downEdge = (e) => this.pressEdge(e);
            const down = (e) => this.pressTile(e);
            const move = (e) => this.pointerMove(e);
            const up = (e) => {
                if (pull && e.pointerId === pull.id) this.endPull(true);
                if (carry && e.pointerId === carry.id) this.endCarry(true);
            };
            const cancel = () => {
                this.endPull(false);
                this.endCarry(false);
            };
            const key = (e) => {
                if (e.key !== 'Escape' || ! (pull || carry?.active)) return;
                e.preventDefault();
                e.stopPropagation();
                cancel();
            };
            // Once a tile is held or pulled on a touch screen, the finger moves it instead of the page.
            const touch = (e) => {
                if (carry?.active || pull) e.preventDefault();
            };
            // A finger held on a tile must not open the browser's menu, nor a link or an image start the browser's own drag.
            const noDefault = (e) => {
                if (carry || pull) e.preventDefault();
            };
            // Nor does carrying a tile select the text it passes over.
            const noSelect = (e) => {
                if (carry?.active || pull) e.preventDefault();
            };

            grid.addEventListener('pointerdown', downEdge, true);
            grid.addEventListener('pointerdown', down);
            grid.addEventListener('contextmenu', noDefault);
            grid.addEventListener('dragstart', noDefault);
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', cancel);
            window.addEventListener('blur', cancel);
            window.addEventListener('keydown', key, true);
            document.addEventListener('touchmove', touch, { passive: false });
            document.addEventListener('selectstart', noSelect);
            cleanup.push(() => {
                document.removeEventListener('selectstart', noSelect);
                grid.removeEventListener('pointerdown', downEdge, true);
                grid.removeEventListener('pointerdown', down);
                grid.removeEventListener('contextmenu', noDefault);
                grid.removeEventListener('dragstart', noDefault);
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', cancel);
                window.removeEventListener('blur', cancel);
                window.removeEventListener('keydown', key, true);
                document.removeEventListener('touchmove', touch, { passive: false });
            });
        },
        pointerMove(e) {
            if (pull) {
                if (e.pointerId === pull.id) this.pullTo(e);

                return;
            }
            if (! carry || e.pointerId !== carry.id) return;
            carry.x = e.clientX;
            carry.y = e.clientY;
            const mouse = carry.pointer === 'mouse';
            if (! carry.active) {
                const travelled = Math.hypot(e.clientX - carry.startX, e.clientY - carry.startY);
                if (mouse && e.buttons === 0) this.endCarry(false);
                else if (mouse && travelled > MOVE_THRESHOLD) this.pickUp();
                else if (! mouse && travelled > HOLD_SLOP) this.endCarry(false); // a swipe: the page scrolls

                return;
            }
            // The button came up outside the window.
            if (mouse && e.buttons === 0) {
                this.endCarry(true);

                return;
            }
            e.preventDefault();
            this.reflow();
            this.follow();
        },

        // ---- move: pick a tile up and carry it ---------------------------------
        // A press on a tile may become a move: not on its controls, its handles,
        // its open panel, nor on a pinned tile (pinned tiles stay first).
        pressTile(e) {
            if (carry || pull || e.button !== 0 || ! e.isPrimary || this.gallery || this.$store.confirm?.open) return;
            const target = e.target instanceof Element ? e.target : null;
            const tile = target?.closest('.hc-tile');
            if (! tile || tile.parentElement !== this.$refs.grid || target.closest(OPEN)) return;
            const control = target.closest(NOT_HANDLE);
            if (control && ! control.matches(FACE)) return;
            const key = tile.dataset.key;
            if (! key || this.pinned(key)) return;
            const box = tile.getBoundingClientRect();
            carry = {
                key,
                tile,
                id: e.pointerId,
                pointer: e.pointerType,
                startX: e.clientX,
                startY: e.clientY,
                x: e.clientX,
                y: e.clientY,
                grabX: e.clientX - box.left,
                grabY: e.clientY - box.top,
                active: false,
                hold: null,
                frame: null,
                last: null,
            };
            if (e.pointerType !== 'mouse') {
                const mine = carry;
                mine.hold = setTimeout(() => {
                    if (carry === mine && ! mine.active) this.pickUp();
                }, HOLD_DELAY);
            }
        },
        pickUp() {
            const c = carry;
            this.closePeek(false);
            this.bakeAtRest(c.key);
            c.active = true;
            c.order = this.tiles();
            c.items = [...this.items];
            c.keys = this.items.map((i) => i.key).join(',');
            c.rtl = this.metrics().rtl;
            c.scroller = scrollerOf(this.$refs.grid);
            glides.get(c.tile)?.cancel();
            glides.delete(c.tile);
            c.tile.style.transition = 'none';
            c.tile.classList.add('carried');
            this.$refs.grid.classList.add('moving');
            document.documentElement.classList.add('hc-moving-doc');
            this.carrying = true;
            try {
                window.getSelection()?.removeAllRanges();
            } catch (err) {
                // Nothing selected.
            }
            // A short buzz on phones once a held tile comes loose (browsers refuse it without a real touch).
            try {
                if (c.pointer !== 'mouse' && navigator.vibrate && navigator.userActivation?.isActive) navigator.vibrate(12);
            } catch (err) {
                // No vibration: nothing to do.
            }
            this.follow();
            this.autoScroll();
        },
        // The carried tile stays under the pointer, measured from wherever its place is now.
        follow(c = carry) {
            if (! c) return;
            const { tile, x, y, grabX, grabY } = c;
            tile.style.transform = '';
            const slot = tile.getBoundingClientRect();
            tile.style.transform = `translate(${x - grabX - slot.left}px, ${y - grabY - slot.top}px) scale(1.02)`;
        },
        /*
         * The tile under the pointer decides the place: before it, or after it
         * once the pointer is past its middle (dropsAfter). The list follows the
         * page's order at once, so the packed places change and the others glide
         * to them; the same tile and side twice in a row is not done again, so
         * tiles of different sizes do not swap back and forth under the pointer.
         */
        reflow() {
            const c = carry;
            const grid = this.$refs.grid;
            c.tile.style.pointerEvents = 'none';
            const hit = document.elementFromPoint(c.x, c.y);
            c.tile.style.pointerEvents = '';
            const target = hit instanceof Element && ! hit.closest(OPEN) ? hit.closest('.hc-tile') : null;
            if (! target || target === c.tile || target.parentElement !== grid) {
                c.last = null;

                return;
            }
            const key = target.dataset.key;
            // Pinned tiles stay first; a closed tile is drawn last whatever its place in the order.
            if (this.pinned(key) || this.closed(key)) return;
            const after = dropsAfter(target.getBoundingClientRect(), c.x, c.rtl);
            if (c.last && c.last.target === target && c.last.after === after) return;
            c.last = { target, after };
            if (! reorder(this.tiles().map((t) => t.dataset.key), c.key, key, after)) return;
            this.flip(() => {
                if (after) target.after(c.tile);
                else target.before(c.tile);
                this.followDom();
            }, c.key);
            // Its own place moved too: keep it under the pointer once the grid has it.
            this.$nextTick(() => {
                if (carry === c && c.active) this.follow(c);
            });
        },
        // Near the window's top or bottom edge the page scrolls, faster the closer.
        autoScroll() {
            const c = carry;
            if (! c?.active) return;
            const box = c.scroller === window ? { top: 0, bottom: window.innerHeight } : c.scroller.getBoundingClientRect();
            const top = Math.max(0, box.top);
            const bottom = Math.min(window.innerHeight, box.bottom);
            const speed = c.y < top + SCROLL_EDGE ? -(top + SCROLL_EDGE - c.y) / 3
                : c.y > bottom - SCROLL_EDGE ? (c.y - (bottom - SCROLL_EDGE)) / 3 : 0;
            if (speed) {
                c.scroller.scrollBy(0, speed);
                this.reflow();
                this.follow(c);
            }
            c.frame = requestAnimationFrame(() => this.autoScroll());
        },
        // Release (commit) or Escape / a cancelled pointer (put everything back).
        endCarry(commit) {
            const c = carry;
            if (! c) return;
            carry = null;
            clearTimeout(c.hold);
            if (! c.active) return;
            cancelAnimationFrame(c.frame);
            swallowNext = true;
            setTimeout(() => {
                swallowNext = false;
            }, 0);
            c.tile.classList.remove('carried');
            this.$refs.grid?.classList.remove('moving');
            document.documentElement.classList.remove('hc-moving-doc');
            this.carrying = false;
            if (! commit) {
                this.flip(() => {
                    c.order.forEach((t) => t.isConnected && this.$refs.grid.appendChild(t));
                    this.items = c.items;
                }, c.key);
            } else if (this.items.map((i) => i.key).join(',') !== c.keys) {
                this.syncOrder();
                const shown = this.tiles().filter((t) => t.offsetParent !== null);
                this.announce(this.text.moved, { tile: this.labelOf(c.key), n: shown.indexOf(c.tile) + 1, total: shown.length });
            }
            // Back into its place, from where it was let go.
            this.$nextTick(() => {
                const { tile } = c;
                tile.style.transition = 'none';
                this.follow(c);
                tile.getBoundingClientRect();
                tile.style.transition = reducedMotion() ? '' : `transform .26s ${GLIDE}`;
                tile.style.transform = '';
                setTimeout(() => {
                    tile.style.transition = '';
                }, 300);
            });
        },

        // ---- resize: pull an edge or a corner, cell by cell ---------------------
        // An edge handle (data-resize="end|start|top|bottom|corner"), on a computer only.
        pressEdge(e) {
            const handle = e.target instanceof Element ? e.target.closest('[data-resize]') : null;
            const tile = handle?.closest('.hc-tile');
            if (! tile || tile.parentElement !== this.$refs.grid || e.button !== 0 || this.phone() || narrowScreen()) return;
            e.preventDefault();
            e.stopPropagation();
            this.beginPull(e, tile.dataset.key, handle.dataset.resize, handle);
        },
        // The grip in the inline-end corner while customising: the same as the corner handle.
        resizeStart(e, key) {
            if (e.button > 0) return;
            e.preventDefault();
            e.stopPropagation();
            this.beginPull(e, key, 'corner', e.currentTarget instanceof Element ? e.currentTarget : e.target);
        },
        beginPull(e, key, edge, handle) {
            if (pull || carry?.active || ! this.tileEl(key)) return;
            this.endCarry(false);
            this.closePeek(false);
            this.bakeAtRest(key);
            const it = this.item(key);
            const rect = this.tileEl(key).getBoundingClientRect();
            pull = {
                key,
                edge,
                id: e.pointerId,
                x: e.clientX,
                y: e.clientY,
                w: rect.width,
                h: rect.height,
                m: this.metrics(),
                size: it.size,
                fixed: it.fixed,
                hadFixed: Object.prototype.hasOwnProperty.call(it, 'fixed'),
                scroller: scrollerOf(this.$refs.grid),
                changed: false,
            };
            try {
                handle?.setPointerCapture?.(e.pointerId);
            } catch (err) {
                // Capture is a nicety: the window still hears the pointer.
            }
            this.resizing = key;
            this.resizeLabel = this.sizeText(key);
            document.documentElement.classList.add('hc-resizing-doc');
            document.documentElement.dataset.resizeEdge = edge;
        },
        /*
         * The size follows the pointer from where the pull started: each handle
         * grows the tile its own way (edgeGrowth), towards the inline end
         * mirrored in Arabic (inlineDelta), snapping only once the pointer is
         * clearly into the next cell (stepCells). Pulling the top edge up scrolls
         * the page by the rows it adds, so the bottom edge stays where it was.
         */
        pullTo(e) {
            const p = pull;
            e.preventDefault();
            const [gx, gy] = edgeGrowth(p.edge);
            const { gap, colW, rowH, rtl } = p.m;
            const [curC, curR] = parse(this.item(p.key).size);
            const shownC = Math.min(curC, this.cols);
            const c = gx ? stepCells(rawCells(p.w + gx * inlineDelta(e.clientX - p.x, rtl), colW, gap), shownC) : shownC;
            const r = gy ? stepCells(rawCells(p.h + gy * (e.clientY - p.y), rowH, gap), curR) : curR;
            if (this.setCells(p.key, c, r)) {
                p.changed = true;
                const grown = parse(this.item(p.key).size)[1] - curR;
                if (p.edge === 'top' && grown) p.scroller.scrollBy(0, grown * (rowH + gap));
            }
            this.resizeLabel = this.sizeText(p.key);
        },
        endPull(commit) {
            const p = pull;
            if (! p) return;
            pull = null;
            this.resizing = null;
            document.documentElement.classList.remove('hc-resizing-doc');
            delete document.documentElement.dataset.resizeEdge;
            if (p.changed) {
                swallowNext = true;
                setTimeout(() => {
                    swallowNext = false;
                }, 0);
                if (commit) {
                    this.save();
                    this.afterResize(p.key);
                } else {
                    const it = this.item(p.key);
                    this.flip(() => {
                        it.size = p.size;
                        if (p.hadFixed) it.fixed = p.fixed;
                        else delete it.fixed;
                    }, p.key);
                }
            }
            this.$nextTick(() => this.drawGuides());
        },
        // Keyboard: arrow keys on the grip add or remove a column or a row (towards the inline end grows).
        resizeKey(e, key) {
            const [c, r] = parse(this.item(key).size);
            const step = resizeStep(e.key, this.metrics().rtl);
            if (! step) return;
            e.preventDefault();
            e.stopPropagation();
            if (this.setCells(key, Math.min(c, this.cols) + step[0], r + step[1])) {
                this.save();
                this.afterResize(key);
            }
        },
        afterResize(key) {
            this.announce(this.text.resized, { tile: this.labelOf(key), size: this.sizeWords(key) });
            // Charts and sliders that only follow the window hear about the new size too.
            this.$nextTick(() => window.dispatchEvent(new Event('resize')));
        },

        // ---- smooth layout changes (FLIP) ------------------------------------
        /*
         * FLIP without jitter:
         *  1. note where each tile is *seen* now (mid-glide included);
         *  2. apply the change;
         *  3. stop any glide still running, so the new places are measured
         *     without it (measuring through a running glide made tiles jump);
         *  4. glide each tile from where it was seen to its new place.
         * A tile that is still gliding therefore continues smoothly from where
         * it is, instead of restarting from somewhere else.
         */
        flip(mutate, stillKey = null) {
            const visible = () => this.tiles().filter((t) => t.offsetParent !== null);
            const before = new Map(visible().map((t) => [t, t.getBoundingClientRect()]));
            mutate();
            this.$nextTick(() => {
                this.drawGuides();
                const tiles = visible();
                for (const t of tiles) {
                    const running = glides.get(t);
                    if (running) {
                        running.cancel();
                        glides.delete(t);
                    }
                }
                if (reducedMotion()) return;
                for (const t of tiles) {
                    const a = before.get(t);
                    const b = t.getBoundingClientRect();
                    let anim = null;
                    if (! a) {
                        anim = t.animate([{ opacity: 0, transform: 'scale(.9)' }, { opacity: 1, transform: 'none' }], { duration: 220, easing: EASE });
                    } else if (t.dataset.key !== stillKey) {
                        // The tile being carried or resized follows the pointer; only the others glide.
                        const dx = a.left - b.left;
                        const dy = a.top - b.top;
                        if (Math.abs(dx) >= 1 || Math.abs(dy) >= 1) {
                            anim = t.animate([{ transform: `translate3d(${dx}px, ${dy}px, 0)` }, { transform: 'translate3d(0, 0, 0)' }], { duration: 320, easing: GLIDE });
                        }
                    }
                    if (anim) {
                        glides.set(t, anim);
                        anim.onfinish = () => {
                            if (glides.get(t) === anim) glides.delete(t);
                        };
                    }
                }
            });
        },

        // The DOM order is the truth after a drag or a move. Tiles that are not on
        // the page (hidden when it was drawn) keep their places; pinned stay first.
        syncOrder() {
            this.followDom();
            this.save();
        },
        followDom() {
            const order = this.tiles().map((t) => t.dataset.key);
            const onPage = new Set(order);
            const byKey = new Map(this.items.map((i) => [i.key, i]));
            let n = 0;
            const next = this.items.map((it) => (onPage.has(it.key) ? byKey.get(order[n++]) : it));
            this.items = [...next.filter((i) => i.pinned), ...next.filter((i) => ! i.pinned)];
        },

        // ---- saving ----------------------------------------------------------
        payload() {
            return JSON.parse(JSON.stringify(this.items)).map(({ key, size, pinned, hidden, style, fixed }) => ({
                key,
                size,
                pinned,
                hidden,
                ...(style === undefined ? {} : { style }),
                ...(fixed ? { fixed: true } : {}),
            }));
        },
        save() {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => this.flushSave(), 350);
        },
        // One save at a time, so an older one can never arrive after a newer one.
        flushSave() {
            clearTimeout(saveTimer);
            const waiting = saveTimer !== null;
            saveTimer = null;
            if (! waiting) return inflight;
            if (inflight) {
                saveAgain = true;

                return inflight;
            }
            inflight = this.$wire.saveLayout(this.payload())
                .catch(() => {})
                .finally(() => {
                    inflight = null;
                    if (saveAgain) {
                        saveAgain = false;
                        saveTimer = 0;
                        this.flushSave();
                    }
                });

            return inflight;
        },
        // Everything sent and answered (before Add or Reset re-render the page).
        async settle() {
            if (saveTimer !== null) this.flushSave();
            while (inflight) await inflight;
        },

        // ---- a tile that cannot load at all ------------------------------------
        // Each card handles its own failures; this is for one whose lazy load
        // fails outright (an error page): the tile says so, the page carries on.
        watchFailures() {
            if (! window.Livewire?.interceptRequest) return;
            cleanup.push(window.Livewire.interceptRequest(({ request, onError }) => {
                onError(({ preventDefault }) => {
                    const tiles = [...request.messages].map((message) => {
                        const lazy = [...(message.actions || [])].some((action) => action.name === '__lazyLoad');
                        const el = message.component?.el;

                        return lazy && el && this.$refs.grid?.contains(el) ? el.closest('.hc-tile') : null;
                    });
                    if (! tiles.length || tiles.includes(null)) return;
                    preventDefault();
                    tiles.forEach((tile) => this.showTileError(tile));
                });
            }));
        },
        showTileError(tile) {
            const body = tile.querySelector('.hc-tile-body');
            const error = this.$refs.tileError?.content.firstElementChild?.cloneNode(true);
            if (! body || ! error) return;
            error.setAttribute('role', 'alert');
            body.replaceChildren(error);
        },
    };
}

const register = () => window.Alpine.data('hcHomeScreen', homeScreen);

// On a full load Alpine is there but not started; after wire:navigate it is running.
if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
