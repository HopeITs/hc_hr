/*
 * The dashboard as a desk, like hc-tracking's Desk (resources/js/desk-grid.js
 * there). Loaded on the dashboard only: its own Vite entry, included by
 * livewire/dashboard/index.blade.php.
 *
 * A grid of cells (12 columns on a wide screen, 8 on a computer, 4 on a phone;
 * resources/css/dashboard.css). Each panel is C×R cells. Panels are moved and
 * resized where they are, with no mode to enter first. What the person does
 * happens in the browser straight away:
 *
 *   - move: a mouse picks a panel up after a few pixels of travel; a finger or
 *     a pen picks it up after a 400ms press and hold, so a swipe still
 *     scrolls. The panel follows the pointer, and the others slide out of the
 *     way to where they will be, so what is seen is where it lands. Near the
 *     top or bottom of the window the page scrolls. Escape puts everything
 *     back. Links, buttons and the panel's own tools are not handles, and the
 *     click that ends a real move never opens anything;
 *   - resize: pull any edge of a panel, or its inline-end corner (on a
 *     computer, not a phone). It snaps cell by cell between the panel's own
 *     minimum and maximum, with a "2×3" badge while pulling. Pulling the top
 *     edge scrolls the page with it, so the bottom edge stays put. Escape puts
 *     the old size back;
 *   - the panel's ⋯ menu: move it to the top, earlier or later, change the
 *     clock's look, or remove it (Undo for a few seconds);
 *   - "Customize your desk" (a sheet): a ready layout, or the panels switched
 *     on and off one by one, and Reset. Nothing changes until it is confirmed;
 *     then the server stores the new layout and draws it.
 *
 * The first move or resize starts from exactly what is on screen (bake()), so
 * nothing jumps. Panels always sit exactly where they will be saved: the
 * packed places (layout-math.js pack()), live while carrying or pulling; a
 * size set by hand is kept as set (fixed). The wheel stays in a card only
 * where there is more to scroll there, and a phone does not draw the heavy
 * panels (PHONE_HIDDEN).
 *
 * Changes glide into place (FLIP) unless the person prefers less motion. The
 * layout is saved in the background ($wire.saveLayout is renderless), one
 * request at a time so an older save can never land after a newer one. The
 * sheet, Reset and Undo for a panel the page no longer draws re-render the
 * page.
 *
 * Used as x-data="hcHomeScreen" with the layout in data-home (the server
 * keeps that attribute current, so after a re-render the new layout is read back).
 */

import { bakedSize, clampSize, dropsAfter, edgeGrowth, inlineDelta, pack, parse, phoneView, rawCells, reorder, stepCells } from './layout-math.js';
// The clock panel's x-data (hcClock), on the dashboard only, like this script.
import './clock.js';

const EASE = 'cubic-bezier(.2,.8,.2,1)';
// Panels gliding to a new place overshoot a touch, like the Desk's.
const GLIDE = 'cubic-bezier(.2,.9,.25,1.12)';
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const fill = (text, values) => Object.entries(values).reduce((out, [name, value]) => out.replaceAll(':' + name, value), text || '');

// Picking a panel up: a mouse after this much travel, a finger or a pen after this long a hold.
const MOVE_THRESHOLD = 6;
const HOLD_DELAY = 400;
const HOLD_SLOP = 10; // a finger that travels this far before the hold is scrolling
const SCROLL_EDGE = 72; // the page scrolls while a panel is carried this close to the window's top or bottom
const UNDO_FOR = 6000;
// A press on these is the control's own, never the start of a move.
const NOT_HANDLE = 'a, button, input, select, textarea, label, [contenteditable], [role="menu"], [data-resize], .hc-ttools, .hc-pop';
const narrowScreen = () => window.matchMedia('(max-width: 760px)').matches;
// Elements in a card that may scroll on their own.
const SCROLLERS = '.hc-tile-body, .hc-tile-body [class*="overflow-"], .hc-bdays-list, .hc-decide';

// ?customise=1 (the command palette) opened the page with the sheet open; a reload should not.
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
    const glides = new WeakMap(); // glide running, per panel element
    const cleanup = [];
    let carry = null; // the panel being moved (pressed, or picked up: carry.active)
    let pull = null; // the panel being resized
    let swallowNext = false; // the click that ends a real move or resize opens nothing
    let undoTimer = null;
    let undoState = null; // where a panel was before it was removed: { key, index }
    let saveTimer = null;
    let inflight = null; // the save on its way to the server
    let saveAgain = false; // something changed while it was
    let adopting = false; // the sheet or Reset: take the layout the server sends back
    let arriving = null; // Undo: the panel the server is about to draw
    let packCache = { sig: null, value: null };
    let overTile = null; // the panel under the pointer (scroll check)

    return {
        items: [],
        absent: [],
        bounds: {},
        looks: {},
        labels: {},
        text: {},
        cols: 8,
        desk: false, // "Customize your desk": ready layouts and My panels
        deskTab: 'layouts',
        deskPick: null, // the ready layout being looked at
        deskShown: {}, // My panels: key => shown, until confirmed
        deskBusy: false,
        carrying: false, // a panel is picked up and follows the pointer
        resizing: null, // key of the panel being resized
        resizeLabel: '',
        removed: null, // { key, label } of the panel just removed, while Undo is offered
        announcement: '',
        phoneHidden: [],

        init() {
            const config = this.configure();

            const onResize = () => this.readCols();
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
                this.watchScroll();

                if (config.customise) {
                    dropCustomiseParam();
                    this.openDesk('layouts');
                }
            });

            // Leaving the page: send what is still waiting.
            const onNavigating = () => this.flushSave();
            document.addEventListener('livewire:navigating', onNavigating);
            cleanup.push(() => document.removeEventListener('livewire:navigating', onNavigating));

            // The page re-rendered (the sheet, Reset, Undo, a retry): keep the person's order on screen.
            this.$wire.$hook('morphed', () => this.$nextTick(() => this.afterServerRender()));

            this.watchFailures();
        },

        destroy() {
            this.endCarry(false);
            this.endPull(false);
            cleanup.forEach((fn) => fn());
            clearTimeout(undoTimer);
        },

        // ---- the layout ---------------------------------------------------
        // data-home: the layout (hidden panels included), each panel's bounds,
        // looks and name, the panels not drawn (absent) and the words to say.
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

        // The server draws panels in the saved order; the person may have moved some since.
        restoreOrder() {
            const grid = this.$refs.grid;
            const want = this.items.map((it) => this.tileEl(it.key)).filter(Boolean);
            const now = this.tiles();
            if (want.length === now.length && want.every((el, i) => el === now[i])) return;
            want.forEach((el) => grid.appendChild(el));
        },

        // ---- grid ---------------------------------------------------------
        // The column count comes from the CSS (12, 8 or 4). Read the variable
        // itself: counting drawn columns is wrong once panels are placed by
        // number, because a panel past the last column makes the browser add columns.
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
        tiles() {
            return [...this.$refs.grid.querySelectorAll(':scope > .hc-tile')];
        },
        tileEl(key) {
            return this.tiles().find((el) => el.dataset.key === key) || null;
        },

        // ---- state helpers used by the template ---------------------------
        item(key) {
            return this.items.find((i) => i.key === key) || { key, size: '1x1', hidden: true };
        },
        hidden(key) {
            return this.item(key).hidden;
        },
        // A phone (4 columns) keeps its desk light: no slideshow. The saved layout is not changed.
        phone() {
            return this.cols <= 4;
        },
        shownHere(key) {
            return ! this.hidden(key) && ! (this.phone() && this.phoneHidden.includes(key));
        },
        // The panels seen here, in order.
        order() {
            return this.items.filter((i) => this.shownHere(i.key) && ! this.absent.includes(i.key)).map((i) => i.key);
        },
        labelOf(key) {
            return this.labels[key] || key;
        },
        /*
         * Each panel gets its packed place (gaps filled): what is seen is what
         * is saved, and a carried panel reorders the list at once (reflow()) so
         * the others glide to where they will end up. A panel is never wider
         * than the grid. An object, so only these two properties are set and
         * x-show keeps control of display.
         */
        span(key) {
            const p = this.packed[key];
            if (p) return { gridColumn: (p.x + 1) + ' / span ' + p.w, gridRow: (p.y + 1) + ' / span ' + p.h };
            const [c, r] = parse(this.item(key).size);

            return { gridColumn: 'span ' + Math.min(c, this.cols), gridRow: 'span ' + r };
        },
        // The size the panel is shown at, in cells (data-cols / data-rows on the panel).
        shown(key) {
            const p = this.packed[key];
            if (p) return [p.w, p.h];
            const [c, r] = parse(this.item(key).size);

            return [Math.min(c, this.cols), r];
        },
        // Where every visible panel goes, gaps filled (layout-math.js pack()).
        get packed() {
            const shown = this.items.filter((i) => this.shownHere(i.key) && ! this.absent.includes(i.key));
            // A phone shows the clock one row high (phoneView): the packer is told, so nothing is left empty under it.
            const view = phoneView(shown, this.bounds, this.cols);
            const sig = this.cols + '|' + view.shown.map((i) => i.key + ':' + i.size + (i.fixed ? '!' : '')).join(',');
            if (packCache.sig !== sig) packCache = { sig, value: pack(view.shown, this.cols, view.bounds) };

            return packCache.value;
        },
        // Panels with more than one look (the clock: digital or analogue).
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
        announce(template, values) {
            this.announcement = '';
            this.$nextTick(() => {
                this.announcement = fill(template, values);
            });
        },

        // ---- the panel's menu: move, remove ---------------------------------
        // Top is offered from the third place on (the second has Earlier for that).
        canMove(key, where) {
            const keys = this.order();
            const i = keys.indexOf(key);
            if (i < 0) return false;
            if (where === 'top') return i >= 2;
            if (where === 'earlier') return i > 0;
            if (where === 'later') return i < keys.length - 1;

            return false;
        },
        moveTo(key, where) {
            if (carry?.active || pull || ! this.canMove(key, where)) return;
            const keys = this.order();
            const from = keys.indexOf(key);
            const to = where === 'top' ? 0 : where === 'earlier' ? from - 1 : from + 1;
            const el = this.tileEl(key);
            const target = this.tileEl(keys[to]);
            if (! el || ! target) return;
            this.flip(() => (to < from ? target.before(el) : target.after(el)));
            this.syncOrder();
            this.announce(this.text.moved, { tile: this.labelOf(key), n: to + 1, total: keys.length });
            // Its menu closed as it moved: focus stays with the panel, on its ⋯ button.
            this.$nextTick(() => {
                el.scrollIntoView({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
                el.querySelector('.hc-tmenu > .hc-tbtn')?.focus({ preventScroll: true });
            });
        },

        // Swallows the click that ends a real move or resize (the grid listens in the
        // capture phase), so it never opens the link or the menu under the pointer.
        swallowClick(e) {
            if (! swallowNext) return;
            e.preventDefault();
            e.stopPropagation();
            swallowNext = false;
        },

        /*
         * Remove hides a panel. For a few seconds Undo puts it back exactly
         * where it was; after that, the sheet's My panels is where it comes back from.
         */
        remove(key) {
            const index = this.items.findIndex((x) => x.key === key);
            if (index < 0) return;
            const it = this.items[index];
            const keys = this.order();
            const at = keys.indexOf(key);
            const neighbour = keys[at + 1] ?? keys[at - 1] ?? null;
            undoState = { key, index };
            this.flip(() => {
                it.hidden = true;
            });
            this.save();
            this.removed = { key, label: this.labelOf(key) };
            clearTimeout(undoTimer);
            undoTimer = setTimeout(() => this.dismissUndo(), UNDO_FOR);
            // Its menu is gone with it: focus goes to the panel that takes its place.
            this.$nextTick(() => {
                if (neighbour) this.tileEl(neighbour)?.querySelector('.hc-tmenu > .hc-tbtn')?.focus({ preventScroll: true });
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
                this.items = rest;
                // The element is still on the page (x-show): back to its place among the others.
                if (el) {
                    const after = this.items.slice(this.items.indexOf(it) + 1).map((x) => this.tileEl(x.key)).find(Boolean);
                    if (after) after.before(el);
                    else this.$refs.grid.appendChild(el);
                }
            });
            if (! el) {
                // The page was drawn again since (the sheet, Reset): the server draws it now.
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

        // ---- "Customize your desk": a ready layout, or the panels one by one ----
        /*
         * A dialog, like hc-tracking's desk: Ready layouts shows each layout as a
         * mosaic of its panels, the suggested one first; My panels switches
         * panels on and off. Nothing changes until it is confirmed; then the
         * server stores the new layout and draws it (like Reset).
         */
        openDesk(tab = 'layouts') {
            this.endCarry(false);
            this.endPull(false);
            this.deskTab = tab === 'panels' ? 'panels' : 'layouts';
            this.deskPick ??= this.$root.dataset.deskDefault || null;
            this.deskShown = Object.fromEntries(this.items.map((it) => [it.key, ! it.hidden]));
            this.desk = true;
        },
        closeDesk() {
            if (this.deskBusy) return;
            this.desk = false;
            this.$nextTick(() => document.querySelector('[data-testid="dashboard-customize"]')?.focus({ preventScroll: true }));
        },
        deskChanged() {
            return this.items.some((it) => !! this.deskShown[it.key] === it.hidden);
        },
        async confirmDesk() {
            if (this.deskBusy) return;
            if (this.deskTab === 'layouts' ? ! this.deskPick : ! this.deskChanged()) {
                this.closeDesk();

                return;
            }
            this.deskBusy = true;
            this.dismissUndo();
            try {
                await this.settle();
                adopting = true;
                if (this.deskTab === 'layouts') {
                    await this.$wire.applyPreset(this.deskPick);
                } else {
                    // Kept in place; a panel switched on goes to the end.
                    const on = [];
                    const rest = this.items.filter((it) => {
                        const want = !! this.deskShown[it.key];
                        if (want && it.hidden) {
                            on.push({ ...it, hidden: false });

                            return false;
                        }
                        if (! want) it.hidden = true;

                        return true;
                    });
                    this.items = [...rest, ...on];
                    await this.$wire.addTile(this.payload());
                }
                this.announce(this.text.deskApplied, {});
            } finally {
                this.deskBusy = false;
                this.closeDesk();
            }
        },

        async reset() {
            const yes = await this.$confirm({
                title: this.text.resetTitle,
                message: this.text.resetMessage,
                confirmLabel: this.text.resetConfirm,
                cancelLabel: this.text.cancel,
            });
            if (! yes) return;
            this.deskBusy = true;
            this.dismissUndo();
            try {
                await this.settle();
                adopting = true;
                await this.$wire.resetLayout();
            } finally {
                this.deskBusy = false;
                if (this.desk) this.closeDesk();
            }
        },

        // Escape: the sheet has its own; a carry or a pull is put back.
        escape(e) {
            if (this.desk || ! (pull || carry?.active)) return;
            e.preventDefault();
            this.endPull(false);
            this.endCarry(false);
        },

        // ---- the wheel: a card keeps it only where there is more to scroll ----
        // Checked as the pointer comes onto a panel, since its content may have changed.
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

        // ---- the pointer: move and resize -------------------------------------
        /*
         * One set of listeners for both (the Desk's engine, desk-grid.js). The
         * grid hears the press: an edge handle in the capture phase (it is the
         * handle's own), anywhere else on a panel in the bubble phase. The window
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
            // Once a panel is held or pulled on a touch screen, the finger moves it instead of the page.
            const touch = (e) => {
                if (carry?.active || pull) e.preventDefault();
            };
            // A finger held on a panel must not open the browser's menu, nor a link or an image start the browser's own drag.
            const noDefault = (e) => {
                if (carry || pull) e.preventDefault();
            };
            // Nor does carrying a panel select the text it passes over.
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

        // ---- move: pick a panel up and carry it --------------------------------
        // A press on a panel may become a move: not on its controls, its tools or its handles.
        pressTile(e) {
            if (carry || pull || e.button !== 0 || ! e.isPrimary || this.desk || this.$store.confirm?.open) return;
            const target = e.target instanceof Element ? e.target : null;
            const tile = target?.closest('.hc-tile');
            if (! tile || tile.parentElement !== this.$refs.grid || target.closest(NOT_HANDLE)) return;
            const key = tile.dataset.key;
            if (! key) return;
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
            // A short buzz on phones once a held panel comes loose (browsers refuse it without a real touch).
            try {
                if (c.pointer !== 'mouse' && navigator.vibrate && navigator.userActivation?.isActive) navigator.vibrate(12);
            } catch (err) {
                // No vibration: nothing to do.
            }
            this.follow();
            this.autoScroll();
        },
        // The carried panel stays under the pointer, measured from wherever its place is now.
        follow(c = carry) {
            if (! c) return;
            const { tile, x, y, grabX, grabY } = c;
            tile.style.transform = '';
            const slot = tile.getBoundingClientRect();
            tile.style.transform = `translate(${x - grabX - slot.left}px, ${y - grabY - slot.top}px) scale(1.02)`;
        },
        /*
         * The panel under the pointer decides the place: before it, or after it
         * once the pointer is past its middle (dropsAfter). The list follows the
         * page's order at once, so the packed places change and the others glide
         * to them; the same panel and side twice in a row is not done again, so
         * panels of different sizes do not swap back and forth under the pointer.
         */
        reflow() {
            const c = carry;
            const grid = this.$refs.grid;
            c.tile.style.pointerEvents = 'none';
            const hit = document.elementFromPoint(c.x, c.y);
            c.tile.style.pointerEvents = '';
            const target = hit instanceof Element ? hit.closest('.hc-tile') : null;
            if (! target || target === c.tile || target.parentElement !== grid) {
                c.last = null;

                return;
            }
            const after = dropsAfter(target.getBoundingClientRect(), c.x, c.rtl);
            if (c.last && c.last.target === target && c.last.after === after) return;
            c.last = { target, after };
            if (! reorder(this.tiles().map((t) => t.dataset.key), c.key, target.dataset.key, after)) return;
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
                const keys = this.order();
                this.announce(this.text.moved, { tile: this.labelOf(c.key), n: keys.indexOf(c.key) + 1, total: keys.length });
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

        /*
         * The first move or resize starts from exactly what is on screen: each
         * panel takes the size and place it is shown at (after packing and gap
         * filling), and the order of the panels on screen becomes the order.
         * Panels not drawn here keep their places in the list. A size that only
         * comes from this screen's width is not taken (bakedSize). The panel
         * being handled does not glide (it follows the pointer).
         */
        bakeAtRest(key) {
            this.flip(() => {
                const p = this.packed;
                const at = (i) => p[i.key].y * 1000 + p[i.key].x;
                const placed = this.items.filter((it) => p[it.key]).sort((a, b) => at(a) - at(b));
                let n = 0;
                this.items = this.items.map((it) => (p[it.key] ? placed[n++] : it));
                for (const it of this.items) {
                    if (p[it.key] && ! it.fixed) it.size = bakedSize(it.size, p[it.key], this.cols);
                }
                this.restoreOrder();
            }, key);
        },

        // ---- resize: pull an edge or a corner, cell by cell ---------------------
        // An edge handle (data-resize="end|start|top|bottom|corner"), on a computer only.
        pressEdge(e) {
            const handle = e.target instanceof Element ? e.target.closest('[data-resize]') : null;
            const tile = handle?.closest('.hc-tile');
            if (! tile || tile.parentElement !== this.$refs.grid || e.button !== 0 || this.desk || this.phone() || narrowScreen()) return;
            e.preventDefault();
            e.stopPropagation();
            this.beginPull(e, tile.dataset.key, handle.dataset.resize, handle);
        },
        beginPull(e, key, edge, handle) {
            if (pull || carry?.active || ! this.tileEl(key)) return;
            this.endCarry(false);
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
         * grows the panel its own way (edgeGrowth), towards the inline end
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
            if (! p.changed) return;
            swallowNext = true;
            setTimeout(() => {
                swallowNext = false;
            }, 0);
            if (commit) {
                this.save();
                this.announce(this.text.resized, { tile: this.labelOf(p.key), size: this.sizeWords(p.key) });
                // Charts and sliders that only follow the window hear about the new size too.
                this.$nextTick(() => window.dispatchEvent(new Event('resize')));

                return;
            }
            const it = this.item(p.key);
            this.flip(() => {
                it.size = p.size;
                if (p.hadFixed) it.fixed = p.fixed;
                else delete it.fixed;
            }, p.key);
        },

        // ---- smooth layout changes (FLIP) ------------------------------------
        /*
         * FLIP without jitter:
         *  1. note where each panel is *seen* now (mid-glide included);
         *  2. apply the change;
         *  3. stop any glide still running, so the new places are measured
         *     without it (measuring through a running glide made panels jump);
         *  4. glide each panel from where it was seen to its new place.
         * A panel that is still gliding therefore continues smoothly from where
         * it is, instead of restarting from somewhere else.
         */
        flip(mutate, stillKey = null) {
            const visible = () => this.tiles().filter((t) => t.offsetParent !== null);
            const before = new Map(visible().map((t) => [t, t.getBoundingClientRect()]));
            mutate();
            this.$nextTick(() => {
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
                        // The panel being carried or resized follows the pointer; only the others glide.
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

        // The DOM order is the truth after a carry or a move. Panels that are
        // not on the page (hidden when it was drawn) keep their places.
        syncOrder() {
            this.followDom();
            this.save();
        },
        followDom() {
            const order = this.tiles().map((t) => t.dataset.key);
            const onPage = new Set(order);
            const byKey = new Map(this.items.map((i) => [i.key, i]));
            let n = 0;
            this.items = this.items.map((it) => (onPage.has(it.key) ? byKey.get(order[n++]) : it));
        },

        // ---- saving ----------------------------------------------------------
        payload() {
            return JSON.parse(JSON.stringify(this.items)).map(({ key, size, hidden, style, fixed }) => ({
                key,
                size,
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
        // Everything sent and answered (before the sheet, Reset or Undo re-render the page).
        async settle() {
            if (saveTimer !== null) this.flushSave();
            while (inflight) await inflight;
        },

        // ---- a panel that cannot load at all ------------------------------------
        // Each card handles its own failures; this is for one whose lazy load
        // fails outright (an error page): the panel says so, the page carries on.
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
