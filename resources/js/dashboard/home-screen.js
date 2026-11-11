/*
 * The dashboard as a home screen, phone style (ported from media-request's
 * public/js/home-screen.js). Loaded on the dashboard only: its own Vite entry,
 * included by livewire/dashboard/index.blade.php, with SortableJS fetched the
 * first time someone customises.
 *
 * A grid of cells (12 columns on a wide screen, 8 on a computer, 4 on a phone;
 * resources/css/dashboard.css). Each tile is C×R cells. What the person does
 * happens in the browser straight away:
 *
 *   - a long press on a tile, or "Customise", starts editing: the tiles wiggle
 *     briefly and a faint grid of the cells appears behind them;
 *   - drag a tile to move it (SortableJS; the others slide aside), or focus it
 *     and use the arrow keys;
 *   - drag the handle in a tile's inline-end corner to resize it cell by cell,
 *     between the tile's own minimum and maximum, with a "2×3" label while
 *     dragging; with the keyboard, focus the handle and use the arrow keys;
 *   - × hides a tile, the pin keeps it first, Add brings one back;
 *   - a click on empty space (not a tile, a control or a sheet) ends it.
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
 *   - a phone does not draw the heavy tiles (PHONE_HIDDEN);
 *   - a card works like a window (2026-11-02): its corner grip resizes it cell
 *     by cell without Customise (pointer screens; on touch, in Customise);
 *     minimise puts it in the strip under the grid (min: a hidden tile that
 *     comes back in place, rather than through Add); maximise opens it large
 *     in the panel, which Escape or × closes.
 *
 * While customising, tiles sit exactly where they will be saved: the packed
 * places, live while dragging; a size set by hand is kept as set (fixed).
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

import { bakedSize, clampSize, iconish, inlineDelta, isApp, moveStep, pack, parse, phoneView, resizeStep, stepCells } from './layout-math.js';
// The clock tile's x-data (hcClock), on the dashboard only, like this script.
import './clock.js';
// The workspace over the dashboard (hcWorkspace): pages open here instead of leaving.
import './workspace.js';

const EASE = 'cubic-bezier(.2,.8,.2,1)';
// A resized card settles into its new size with a small, soft overshoot.
const SETTLE = 'cubic-bezier(.22,1.18,.36,1)';
// Cards coming in (the Sutask reference, 2026-11-06): each fades in while sliding from the inline
// end and slows right down at the end (an expo ease-out), column by column from the start side.
const ENTER = 'cubic-bezier(.16,1,.3,1)';
// Coming back to the dashboard in the same browser tab: which cards were empty and how tall the
// fitted ones were, so the cards show at once instead of waiting for the lazy ones again.
const MEMORY = 'hc-dash-memory';
const recall = () => {
    try {
        const m = JSON.parse(sessionStorage.getItem(MEMORY) || 'null');

        return m && typeof m.empty === 'object' && typeof m.fit === 'object' ? m : null;
    } catch (e) {
        return null;
    }
};
const remember = (m) => {
    try {
        sessionStorage.setItem(MEMORY, JSON.stringify(m));
    } catch (e) {
        // private mode or full: the dashboard simply waits like on a first visit
    }
};
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const fill = (text, values) => Object.entries(values).reduce((out, [name, value]) => out.replaceAll(':' + name, value), text || '');

let sortableModule = null;
const loadSortable = () => (sortableModule ??= import('sortablejs')
    .then((module) => module.default)
    .catch((error) => {
        sortableModule = null;
        throw error;
    }));

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

function homeScreen() {
    // Kept outside Alpine's reactive state.
    const glides = new WeakMap(); // glide running, per tile element
    const entrances = new WeakMap(); // entrance running, per tile element (never cut by a glide)
    const cleanup = [];
    // fitCharts(): one ResizeObserver for every chart canvas in the grid (kept out of Alpine's reactive state).
    let chartWatch = null;
    let sortable = null;
    let pressTimer = null;
    let pressStart = null;
    let wiggleTimer = null;
    let saveTimer = null;
    let inflight = null; // the save on its way to the server
    let saveAgain = false; // something changed while it was
    let adopting = false; // Reset: take the layout the server sends back
    let arriving = null; // Add: the tile the server is about to draw
    let packCache = { sig: null, value: null };
    let clickTimer = null; // one click on an icon waits to see if a second follows
    let overTile = null; // the tile under the pointer (scroll check)
    let downOutside = false; // the press that may end customising started outside
    // The page's own $wire, found from the root. $wire finds its component the first time
    // it is used, from the element the expression ran on: from a button in an x-for that
    // the change itself removes (the minimised strip), that lookup came too late and the
    // call was silently dropped.
    let rootWire = null;

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
        wiggling: false,
        resizing: null, // key of the tile being resized
        resizeLabel: '',
        pressed: false,
        announcement: '',
        phoneHidden: [],
        empty: {}, // key => the card's own "nothing here" words, for tiles with nothing in them now
        peek: null, // key of the tile whose card is open in the side panel
        maxKey: null, // the panel holds a maximised card, not a small one
        fit: {}, // rows a loaded card says it needs (data-fit-rows), so it is no taller than its content
        groups: {}, // hub group id => the tile keys it shows (DashboardLayout::groupTiles)
        revealed: false, // the cards have come in (reveal()): until then they wait unseen
        group: null, // the hub groups the grid is filtered to (a list, or null for all): the view only, never saved
        defaults: {}, // key => [default size, place] of the role's default dashboard (reset the layout only)
        windowUrl: '', // the page of one card (Dashboard\Window), with __TILE__ for its key
        outKey: null, // the card whose pop-out menu is open
        // Floating on top of every program needs Document Picture-in-Picture (Chrome and Edge on a computer).
        canFloat: typeof window !== 'undefined' && 'documentPictureInPicture' in window,
        resetMenu: false,

        init() {
            rootWire = this.$wire;
            void rootWire.__instance; // look the component up now, from the root
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

                if (config.editing) {
                    dropCustomiseParam();
                    this.revealed = true;
                    this.startEdit();
                } else {
                    this.reveal();
                }
            });

            // Hub groups (the dashboard hub's Categories) filter the grid to their cards; none shows them all.
            const onFilter = (e) => this.filterTo(e.detail?.groups ?? null);
            window.addEventListener('hc-hub-filter', onFilter);
            cleanup.push(() => window.removeEventListener('hc-hub-filter', onFilter));

            // A page from the menu, put on the dashboard: its sidebar link dragged onto the cards
            // (single-item / nav-item set application/x-hc-page to the menu id).
            // Taken anywhere on the page, not only over the cards; and the cards that show a page
            // (frames) let the drag through, since a frame keeps drag events to itself.
            const root = this.$el;
            const html = document.documentElement;
            const isPage = (e) => [...(e.dataTransfer?.types || [])].includes('application/x-hc-page');
            const stop = () => {
                html.classList.remove('hc-page-drag');
                root.classList.remove('hc-drop');
            };
            const onStart = (e) => {
                if (! isPage(e) || this.editing) return;
                html.classList.add('hc-page-drag');
                root.classList.add('hc-drop');
            };
            const onOver = (e) => {
                if (! isPage(e) || this.editing) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                if (! root.classList.contains('hc-drop')) onStart(e);
            };
            const onDrop = (e) => {
                if (! isPage(e)) return;
                e.preventDefault();
                stop();
                const id = e.dataTransfer.getData('application/x-hc-page');
                if (id && ! this.editing) this.pinPage('app.' + id);
            };
            // Bubbling, not capture: the link's own dragstart has put the menu id in by then.
            document.addEventListener('dragstart', onStart);
            document.addEventListener('dragover', onOver);
            document.addEventListener('drop', onDrop);
            document.addEventListener('dragend', stop);
            cleanup.push(() => {
                stop();
                document.removeEventListener('dragstart', onStart);
                document.removeEventListener('dragover', onOver);
                document.removeEventListener('drop', onDrop);
                document.removeEventListener('dragend', stop);
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
                sortable?.option('disabled', ! on);
                if (on) this.closePeek(false);
                this.$nextTick(() => this.drawGuides());
            });
        },

        destroy() {
            cleanup.forEach((fn) => fn());
            sortable?.destroy();
            sortable = null;
            clearTimeout(pressTimer);
            clearTimeout(wiggleTimer);
            clearTimeout(clickTimer);
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
            this.groups = Array.isArray(config.groups) ? {} : config.groups || {};
            this.defaults = Array.isArray(config.defaults) ? {} : config.defaults || {};
            this.windowUrl = config.windowUrl || '';
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
            return ! this.hidden(key) && ! (this.phone() && this.phoneHidden.includes(key)) && this.inGroup(key);
        },
        // Customising always shows every card: a filter never decides what is saved.
        inGroup(key) {
            return ! this.group || this.editing || this.group.some((g) => (this.groups[g] || []).includes(key));
        },
        filterTo(groups) {
            const known = (Array.isArray(groups) ? groups : groups ? [groups] : []).filter((g) => this.groups[g]);
            const next = known.length ? known : null;
            if ((next || []).join() === (this.group || []).join()) return;
            if (this.peek) this.closePeek(false);
            this.flip(() => {
                this.group = next;
            });
            this.announce(next ? this.text.filtered : this.text.unfiltered, { count: this.filteredCount() });
        },
        // Cards on screen while filtered: none says so under the grid, with the way back.
        filteredCount() {
            return this.items.filter((i) => this.shownHere(i.key) && ! this.absent.includes(i.key) && ! this.closed(i.key)).length;
        },
        showAll() {
            this.filterTo(null);
            window.dispatchEvent(new CustomEvent('hc-hub-cleared'));
        },
        // Nothing in it right now: one closed line at the bottom (never while customising, never when pinned).
        // A pinned tile stays where it was pinned and shows its own empty state.
        closed(key) {
            return ! this.editing && this.empty[key] !== undefined && ! isApp(key) && ! this.pinned(key) && ! this.parked(key);
        },
        // Two states only: an open card in the grid, or an entry in the strip under the groups.
        // A card smaller than its minimum (bounds.card) waits in the strip, customising too, and so
        // does every shortcut (it has no card: one click to its page). Not while its own corner
        // is being dragged (it would vanish under the pointer), and not when pinned.
        parked(key) {
            const it = this.item(key);
            if (it.hidden || it.pinned || this.resizing === key) return false;

            return iconish(key, it.size, this.bounds, this.cols);
        },
        isShortcut(key) {
            return isApp(key);
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
         * what is seen is what is saved, and a drag reorders the list at once
         * (SortableJS onChange) so the others glide to where they will end up.
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
            // Tiles with nothing in them take no room at all (the saved order is not changed); customising shows them whole.
            const shown = this.items.filter((i) => this.shownHere(i.key) && ! this.absent.includes(i.key) && ! this.closed(i.key) && ! this.parked(i.key));
            const closed = new Set();
            // A phone shows the clock one row high (phoneView): the packer is told, so nothing is left empty under it.
            const fitted = this.editing ? shown : shown.map((i) => (this.fit[i.key] && ! i.fixed ? { ...i, size: parse(i.size)[0] + 'x' + this.fit[i.key] } : i));
            const view = phoneView(fitted, this.bounds, this.cols, this.editing);
            const sig = this.cols + '|' + (this.editing ? 'e' : '') + '|' + [...closed].join(',') + '|' + view.shown.map((i) => i.key + ':' + i.size + (i.fixed ? '!' : '') + (i.at ? '@' + i.at.join(',') : '')).join(',');
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
            // Sizes are baked from what is on screen: never from a filtered grid.
            if (this.group) this.showAll();
            this.flip(() => {
                this.bake();
                this.editing = true;
            });
            // The tiles wiggle for about a second to show they can move, then rest.
            if (! reducedMotion()) {
                this.wiggling = true;
                clearTimeout(wiggleTimer);
                wiggleTimer = setTimeout(() => {
                    this.wiggling = false;
                }, 1150);
            }
            // A short buzz on phones, only after a real tap (browsers refuse it otherwise).
            try {
                if (navigator.vibrate && navigator.userActivation?.isActive) navigator.vibrate(12);
            } catch (e) {
                // No vibration: nothing to do.
            }
            this.enableSorting();
            // "Customise" is gone: focus moves to Done.
            this.$nextTick(() => this.$refs.done?.focus({ preventScroll: true }));
        },
        /*
         * Editing starts from exactly what is on screen: each tile takes the
         * size and place it is shown at (after packing and gap filling), and the
         * order becomes the on-screen order. Nothing jumps when "Customise" is
         * pressed, and what the person then moves or resizes is what they saw.
         * Saved only once they change something.
         */
        bake() {
            const p = this.packed;
            const at = (i) => (p[i.key] ? p[i.key].y * 1000 + p[i.key].x : 1e9);
            this.items.sort((a, b) => (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0) || at(a) - at(b));
            for (const it of this.items) {
                // A closed tile keeps its own size: customising shows it whole. A size
                // that only comes from this screen's width is not taken (bakedSize).
                if (p[it.key] && ! it.fixed && ! this.closed(it.key)) it.size = bakedSize(it.size, p[it.key], this.cols);
            }
            this.restoreOrder();
        },
        // Any movement ends the wiggle at once, so it never fights a drag or a resize.
        stopWiggle() {
            clearTimeout(wiggleTimer);
            this.wiggling = false;
        },
        done() {
            if (! this.editing) return;
            this.flip(() => {
                this.editing = false;
            });
            this.gallery = false;
            this.stopWiggle();
            this.flushSave();
            this.$nextTick(() => this.$refs.customise?.focus({ preventScroll: true }));
        },
        // Escape leaves editing (Add's own Escape closes Add first; the confirm dialog keeps its own).
        escape(e) {
            if (e.defaultPrevented || this.gallery || this.$store.confirm?.open) return;
            if (this.outKey) {
                const key = this.outKey;
                this.outKey = null;
                this.tileEl(key)?.querySelector('.hc-w-out')?.focus();

                return;
            }
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

        // A long press anywhere on a tile (not while typing in it) starts editing.
        press(e) {
            if (this.editing || e.button > 0 || ! (e.target instanceof Element)
                || e.target.closest('input, textarea, select, [contenteditable], .hc-tbtn, .hc-grip, ' + OPEN)) return;
            this.pressed = false;
            pressStart = { x: e.clientX, y: e.clientY };
            clearTimeout(pressTimer);
            pressTimer = setTimeout(() => {
                pressTimer = null;
                this.pressed = true;
                this.startEdit();
            }, 520);
        },
        // Selecting text or scrolling is not a long press.
        pressMove(e) {
            if (pressTimer && pressStart && Math.hypot(e.clientX - pressStart.x, e.clientY - pressStart.y) > 8) this.release();
        },
        release() {
            clearTimeout(pressTimer);
            pressTimer = null;
            pressStart = null;
        },
        // A long press must not also open the link under the finger.
        swallowClick(e) {
            if (this.pressed) {
                e.preventDefault();
                e.stopPropagation();
                this.pressed = false;
            } else if (this.appClick(e)) {
                // A shortcut: its card in the panel, or its page (appClick).
            } else if (this.editing && e.target.closest('a') && ! e.target.closest('.hc-tbtn, .hc-grip')) {
                e.preventDefault();
            }
        },

        remove(key) {
            const it = this.item(key);
            this.flip(() => {
                it.hidden = true;
                it.pinned = false;
                delete it.at;
                it.min = false;
            });
            this.announce(this.text.hidden, { tile: this.labelOf(key) });
            this.save();
            // Its controls are gone with it; Add is where it comes back from.
            this.$nextTick(() => this.$refs.add?.focus({ preventScroll: true }));
        },
        add(key) {
            const i = this.items.findIndex((x) => x.key === key);
            if (i < 0) return;
            const el = this.tileEl(key);
            this.flip(() => {
                const [it] = this.items.splice(i, 1);
                it.hidden = false;
                it.min = false;
                // Added from the gallery, a card arrives open: at least its minimum card size.
                if (iconish(key, it.size, this.bounds, this.cols)) {
                    const card = (this.bounds[key] || {}).card || [2, 2];
                    it.size = card[0] + 'x' + card[1];
                }
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
            this.settle().then(() => rootWire.addTile(this.payload()));
        },
        // Pin: the card keeps the size and the cell it is shown at now (`at`, `fixed`),
        // the others flow around it; it is not dragged or resized until it is unpinned.
        togglePin(key) {
            const it = this.item(key);
            const p = this.packed[key];
            if (! it.pinned && ! p) return;
            it.pinned = ! it.pinned;
            if (it.pinned) {
                it.size = bakedSize(it.size, p, this.cols);
                it.fixed = true;
                it.at = [p.x, p.y];
                this.syncOrder();
            } else {
                // Pinned cards are kept first in the list; unpinned, this one takes the turn of
                // the place it is shown at, so nothing moves on the screen.
                const placed = this.packed;
                const turn = (i) => (placed[i.key] ? placed[i.key].y * 1000 + placed[i.key].x : 1e9);
                delete it.at;
                const rest = this.items.filter((i) => i !== it);
                const n = rest.findIndex((i) => ! i.pinned && turn(i) > turn(it));
                rest.splice(n < 0 ? rest.length : n, 0, it);
                this.items = rest;
                this.save();
            }
            this.announce(it.pinned ? this.text.pinned : this.text.unpinned, { tile: this.labelOf(key) });
        },
        // ---- windows: minimise to the strip, bring back in place, maximise ----
        // The strip under the groups, in the cards' order: minimised cards, and cards made 1x1
        // (those follow the group filter, like the grid they came from).
        docked() {
            return this.items.filter((i) => ! this.absent.includes(i.key)
                && ((i.hidden && i.min) || (this.parked(i.key) && this.shownHere(i.key))));
        },
        // One click in the strip: a minimised card comes back as it was, a 1x1 card at its card size.
        dockClick(key) {
            if (this.parked(key)) {
                this.unpark(key);

                return;
            }
            this.restore(key);
        },
        // Back in its place in the grid at the smallest size it shows itself as a card at
        // (bounds.card), kept at that size (fixed) rather than grown into the gaps.
        // A page from the menu onto the dashboard: shown as an open card (added, or brought
        // back from the strip), with every card shown so it can be seen arriving.
        pinPage(key) {
            const it = key ? this.items.find((i) => i.key === key) : null;
            if (! it) return; // not a page this role's menu has
            if (this.group) this.showAll();
            if (it.hidden) this.add(key);
            else if (this.parked(key)) this.unpark(key);
            setTimeout(() => this.tileEl(key)?.scrollIntoView({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' }), 350);
        },
        unpark(key) {
            const it = this.item(key);
            const card = (this.bounds[key] || {}).card || [2, 2];
            if (this.peek === key) this.closePeek(false);
            this.flip(() => {
                it.size = card[0] + 'x' + card[1];
                it.fixed = true;
            });
            this.save();
            this.announce(this.text.restored, { tile: this.labelOf(key) });
            this.$nextTick(() => this.tileEl(key)?.focus({ preventScroll: true }));
        },
        // A card's own icon (its tile draws it), for the strip; the window mark when it is not on the page.
        dockIcon(key) {
            return this.tileEl(key)?.querySelector(':scope > .hc-wmini .hc-appicon, .hc-app .hc-appicon')?.innerHTML
                || '<svg viewBox="0 0 16 16" aria-hidden="true"><rect x="3" y="3" width="10" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="1.8" /></svg>';
        },
        minimise(key) {
            const it = this.item(key);
            if (this.peek === key) this.closePeek(false);
            this.flip(() => {
                it.hidden = true;
                it.min = true;
            });
            this.announce(this.text.minimised, { tile: this.labelOf(key) });
            this.save();
            this.$nextTick(() => this.$refs.dock?.querySelector(`[data-key="${CSS.escape(key)}"]`)?.focus({ preventScroll: true }));
        },
        // Back where it was (unlike Add, which puts a tile at the end).
        restore(key) {
            const it = this.item(key);
            if (! it) return;
            const el = this.tileEl(key);
            this.flip(() => {
                it.hidden = false;
                it.min = false;
            });
            if (el) {
                this.save();
                this.announce(this.text.restored, { tile: this.labelOf(key) });
                this.$nextTick(() => el.querySelector(':scope > .hc-wctl .hc-w-min')?.focus({ preventScroll: true }));

                return;
            }
            // Minimised when the page was drawn, so not on it: the server draws it now.
            arriving = key;
            this.settle().then(() => rootWire.addTile(this.payload()));
        },
        // The card large, in the panel: the same element in the top layer, so nothing reloads.
        maximise(key) {
            if (this.peek === key) {
                this.closePeek();

                return;
            }
            this.closePeek(false);
            this.maxKey = key;
            this.peekOpen(key);
            if (this.peek !== key) this.maxKey = null; // the browser could not open it
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
        // Resized from a top or start corner: the card's cell moves by what it gained or lost on
        // that side (it cannot go past the grid's first row or column), so its far edge stays where it was.
        setCellsFrom(key, c, r, cell, baseC, baseR, blockSide, inlineSide) {
            const it = this.item(key);
            if (inlineSide === 'start') c = Math.min(c, baseC + cell.x);
            if (blockSide === 'start') r = Math.min(r, baseR + cell.y);
            [c, r] = clampSize(this.bounds[key], this.cols, c, r);
            const x = inlineSide === 'start' ? cell.x - (c - baseC) : cell.x;
            const y = blockSide === 'start' ? cell.y - (r - baseR) : cell.y;
            const size = c + 'x' + r;
            if (size === it.size && Array.isArray(it.at) && it.at[0] === x && it.at[1] === y) return false;
            // Another card holds its own cell there (pinned, or resized the same way): stop at it,
            // rather than lose the cell and jump back into the flow.
            const hits = this.items.some((o) => o !== it && ! o.hidden && Array.isArray(o.at) && this.packed[o.key]
                && x < o.at[0] + this.packed[o.key].w && o.at[0] < x + c && y < o.at[1] + this.packed[o.key].h && o.at[1] < y + r);
            if (hits) return false;
            this.flip(() => {
                it.size = size;
                it.fixed = true;
                it.at = [x, y];
            }, key);

            return true;
        },
        // Moved to a new place: an unpinned card gives up the cell a resize gave it and flows again.
        unplace(key) {
            const it = this.item(key);
            if (it && ! it.pinned) delete it.at;
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
            await rootWire.resetLayout();
        },
        // ---- out of the browser: one card on a page of its own ------------------
        // Where it can float, the button opens a menu of two; elsewhere it opens a window at once.
        popOut(key) {
            if (! this.canFloat) {
                this.openWindow(key);

                return;
            }
            this.outKey = this.outKey === key ? null : key;
            if (this.outKey) this.$nextTick(() => this.tileEl(key)?.querySelector('.hc-wout [role=menuitem]')?.focus());
        },
        windowFor(key) {
            const rect = this.tileEl(key)?.querySelector(':scope > .hc-tile-body')?.getBoundingClientRect();

            // A page from the menu: its window is the page itself.
            const page = isApp(key) ? this.tileEl(key)?.querySelector('.hc-app')?.getAttribute('href') : null;

            return {
                url: page || this.windowUrl.replace('__TILE__', encodeURIComponent(key)),
                // The card's own size on the grid, within reason for a window.
                width: Math.round(Math.min(Math.max(rect?.width || 420, 320), 900)),
                height: Math.round(Math.min(Math.max(rect?.height || 360, 240), 900)),
            };
        },
        // A window of its own (any browser): as many as the person likes, one per card.
        openWindow(key) {
            this.outKey = null;
            const w = this.windowFor(key);
            const opened = window.open(w.url, 'hopehr-card-' + key.replace(/[^A-Za-z0-9-]/g, '-'), `popup,width=${w.width},height=${w.height}`);
            // Pop-ups blocked: open it as a tab rather than nothing.
            if (! opened) window.open(w.url, '_blank', 'noopener');
        },
        // On top of every program (Document Picture-in-Picture): one at a time; the person
        // drags it to the corner they want and the browser keeps it there. The card is the
        // same page in a frame, so it signs in, checks and refreshes exactly as a window does.
        async floatOnTop(key) {
            this.outKey = null;
            const w = this.windowFor(key);
            let pip;
            try {
                pip = await window.documentPictureInPicture.requestWindow({ width: w.width, height: w.height });
            } catch (e) {
                this.openWindow(key);

                return;
            }
            const doc = pip.document;
            doc.title = this.labelOf(key);
            doc.documentElement.style.cssText = 'height:100%;background:' + getComputedStyle(document.body).backgroundColor;
            doc.body.style.cssText = 'margin:0;height:100%;';
            const frame = doc.createElement('iframe');
            // A page from the menu shows as in the workspace: the page alone, without the site's sidebar and header.
            if (isApp(key)) frame.setAttribute('data-ws-frame', '');
            frame.src = w.url;
            frame.title = this.labelOf(key);
            frame.style.cssText = 'display:block;width:100%;height:100%;border:0;';
            doc.body.append(frame);
        },

        // Reset the layout only: every card back to its default size and place, nothing
        // shown, hidden, added or removed (a card added from a page keeps its place after
        // the default ones). Nothing to ask: the toast's Undo puts it all back.
        resetArrangement() {
            this.resetMenu = false;
            if (this.group) this.showAll();
            const before = this.items.map((it) => ({ ...it }));
            const place = (key) => (this.defaults[key] ? this.defaults[key][1] : Number.MAX_SAFE_INTEGER);
            const next = this.items
                .map((it, i) => ({ it, i }))
                .sort((a, b) => place(a.it.key) - place(b.it.key) || a.i - b.i)
                .map(({ it }) => {
                    const copy = { ...it, pinned: false };
                    delete copy.fixed;
                    delete copy.at;
                    if (this.defaults[it.key]) copy.size = this.defaults[it.key][0];

                    return copy;
                });
            this.arrange(next);
            Alpine.store('toasts')?.push({
                type: 'success',
                message: this.text.layoutReset,
                action: { label: this.text.undo, run: () => this.arrange(before) },
            });
        },
        arrange(items) {
            this.flip(() => {
                this.items = items;
                this.restoreOrder();
            });
            this.save();
        },
        // Reset everything: back to the role's default dashboard exactly, after a confirmation.
        resetAll() {
            this.resetMenu = false;
            if (this.group) this.showAll();

            return this.reset();
        },
        // A ready-made layout replaces the arrangement, so it asks first, like Reset.
        async applyPreset(id) {
            const yes = await this.$confirm({
                title: this.text.presetTitle,
                message: this.text.presetMessage,
                confirmLabel: this.text.presetConfirm,
                cancelLabel: this.text.cancel,
                danger: false,
            });
            if (! yes) return;
            await this.settle();
            adopting = true;
            await rootWire.applyPreset(id);
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
            // A tile with a page simply opens it; only one without a page uses the panel.
            const page = this.tileEl(key)?.dataset.page;
            if (page) {
                navigateTo(page);

                return;
            }
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
            const wasMax = key !== null && this.maxKey === key;
            this.peek = null;
            if (key) this.maxKey = null;
            try {
                if (panel && panel.matches(OPEN)) panel.hidePopover();
            } catch (e) {
                // Already closed.
            }
            if (restore && key) (wasMax ? this.tileEl(key)?.querySelector(':scope > .hc-wctl .hc-w-max') : this.triggerOf(key))?.focus({ preventScroll: true });
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
                    this.fitCharts();
                });
            });
            observer.observe(grid, { childList: true, subtree: true });
            cleanup.push(() => observer.disconnect());
            this.readEmpty();
            this.fitCharts();
        },
        // Chart.js cards (the statistics tiles): Chart.js's own resize watcher misses a
        // canvas that arrives in a lazy tile, so the chart kept its 300x150 default and
        // looked empty. Watch each canvas and its box; a change resizes that chart.
        fitCharts() {
            const grid = this.$refs.grid;
            if (! grid || ! window.ResizeObserver || ! window.Chart?.getChart) return;
            if (! chartWatch) {
                chartWatch = new ResizeObserver((entries) => {
                    for (const entry of entries) {
                        const canvases = entry.target.tagName === 'CANVAS' ? [entry.target] : entry.target.querySelectorAll('canvas');
                        canvases.forEach((canvas) => window.Chart.getChart(canvas)?.resize());
                    }
                });
                cleanup.push(() => chartWatch.disconnect());
            }
            grid.querySelectorAll('.hc-tile-body canvas').forEach((canvas) => {
                if (canvas.dataset.hcWatched) return;
                canvas.dataset.hcWatched = '1';
                chartWatch.observe(canvas);
                if (canvas.parentElement) chartWatch.observe(canvas.parentElement);
            });
        },
        readEmpty() {
            if (! this.$refs.grid?.isConnected) return;
            const next = {};
            const fit = {};
            for (const tile of this.tiles()) {
                const key = tile.dataset.key;
                const body = tile.querySelector(':scope > .hc-tile-body');
                if (! key || isApp(key) || ! body) continue;
                // Still loading: it keeps what it was last known to be (this visit, or remembered
                // from the last one), so a remembered empty card does not open and close again.
                if (body.querySelector('[data-state="loading"]')) {
                    if (key in this.empty) next[key] = this.empty[key];
                    if (this.fit[key]) fit[key] = this.fit[key];
                    continue;
                }
                const rows = parseInt(body.querySelector('[data-fit-rows]')?.dataset.fitRows, 10);
                if (rows > 0) fit[key] = rows;
                const mark = body.querySelector(EMPTY_MARK);
                if (! mark) continue;
                const words = (mark.querySelector('h2, h3') || mark).textContent.replace(/\s+/g, ' ').trim();
                next[key] = words.length <= 80 ? words : '';
            }
            const same = Object.keys(next).length === Object.keys(this.empty).length
                && Object.entries(next).every(([k, v]) => this.empty[k] === v)
                && JSON.stringify(fit) === JSON.stringify(this.fit);
            remember({ empty: next, fit });
            if (same) return;
            // An open panel whose tile closes now stays open: it is the card itself.
            this.flip(() => {
                this.empty = next;
                this.fit = fit;
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
            if (this.pinned(key)) return; // a pinned card keeps its place
            const el = this.tileEl(key);
            const movable = this.tiles().filter((t) => t.offsetParent !== null && ! t.classList.contains('pinned'));
            const from = movable.indexOf(el);
            const to = e.key === 'Home' ? 0 : e.key === 'End' ? movable.length - 1 : from + step;
            if (from < 0 || to < 0 || to >= movable.length || to === from) return;
            this.unplace(key);
            this.flip(() => (to > from ? movable[to].after(el) : movable[to].before(el)));
            this.syncOrder();
            el.focus({ preventScroll: true });
            const shown = this.tiles().filter((t) => t.offsetParent !== null);
            this.announce(this.text.moved, { tile: this.labelOf(key), n: shown.indexOf(el) + 1, total: shown.length });
        },

        // ---- resize: drag the corner grip, cell by cell ---------------------
        // corner: block-inline, "end-end" = bottom inline-end; dragging away from it grows the card.
        resizeStart(e, key, corner = 'end-end') {
            if (this.pinned(key)) return; // a pinned card keeps its size
            if (e.button > 0) return;
            e.preventDefault();
            e.stopPropagation();
            const tile = this.tileEl(key);
            const m = this.metrics();
            const rect = tile.getBoundingClientRect();
            const start = { x: e.clientX, y: e.clientY, w: rect.width, h: rect.height };
            let changed = false;

            this.stopWiggle();
            this.resizing = key;
            this.resizeLabel = this.sizeText(key);
            e.target.setPointerCapture?.(e.pointerId);

            // A light frame follows the pointer pixel by pixel, from the tile's own
            // corner, while the card itself settles on whole cells under it.
            const [blockSide, inlineSide] = corner.split('-');
            const cellPx = (n, unit) => n * unit + (n - 1) * m.gap;
            const b = this.bounds[key] || { min: [1, 1], max: [this.cols, 8] };
            const minW = cellPx(b.min[0], m.colW), maxW = cellPx(Math.min(b.max[0], this.cols), m.colW);
            const minH = cellPx(b.min[1], m.rowH), maxH = cellPx(b.max[1], m.rowH);
            const frame = document.createElement('div');
            frame.className = 'hc-rsz-frame';
            frame.setAttribute('aria-hidden', 'true');
            // Drawn from the corner opposite the grip, so the edge being dragged is the one that moves.
            if (blockSide === 'start') Object.assign(frame.style, { insetBlockStart: 'auto', insetBlockEnd: '0' });
            if (inlineSide === 'start') Object.assign(frame.style, { insetInlineStart: 'auto', insetInlineEnd: '0' });
            // From a top or start corner the card's own cell moves with the edge (`at`), the far edge staying put.
            const fromStart = blockSide === 'start' || inlineSide === 'start';
            const cell = this.packed[key] ? { x: this.packed[key].x, y: this.packed[key].y } : null;
            const [startC, startR] = parse(this.item(key).size);
            const baseC = Math.min(startC, this.cols);
            tile.appendChild(frame);
            let pending = null;
            const drawFrame = () => {
                pending = null;
                if (! frame.isConnected) return;
                frame.style.width = Math.max(minW, Math.min(maxW, last.w)) + 'px';
                frame.style.height = Math.max(minH, Math.min(maxH, last.h)) + 'px';
            };
            const last = { w: start.w, h: start.h };
            drawFrame();

            const move = (ev) => {
                // Dragging outward from the grip's corner grows the tile: towards the
                // inline end (right in English, left in Arabic) from an end corner, the
                // other way from a start corner; down from a bottom corner, up from a top one.
                const dx = inlineDelta(ev.clientX - start.x, m.rtl) * (inlineSide === 'start' ? -1 : 1);
                const dy = (ev.clientY - start.y) * (blockSide === 'start' ? -1 : 1);
                last.w = start.w + dx;
                last.h = start.h + dy;
                pending ??= requestAnimationFrame(drawFrame);
                const rawC = (start.w + dx + m.gap) / (m.colW + m.gap);
                const rawR = (start.h + dy + m.gap) / (m.rowH + m.gap);
                const [curC, curR] = parse(this.item(key).size);
                const c = stepCells(rawC, Math.min(curC, this.cols)), r = stepCells(rawR, curR);
                if (fromStart && cell ? this.setCellsFrom(key, c, r, cell, baseC, startR, blockSide, inlineSide) : this.setCells(key, c, r)) changed = true;
                this.resizeLabel = this.sizeText(key);
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', up);
                if (pending) cancelAnimationFrame(pending);
                // The frame melts onto the card's final size, then goes.
                const body = tile.querySelector(':scope > .hc-tile-body')?.getBoundingClientRect();
                if (body && ! reducedMotion()) {
                    frame.animate([
                        { width: frame.style.width, height: frame.style.height, opacity: 1 },
                        { width: body.width + 'px', height: body.height + 'px', opacity: 0 },
                    ], { duration: 260, easing: SETTLE }).onfinish = () => frame.remove();
                } else {
                    frame.remove();
                }
                this.resizing = null;
                if (changed) {
                    this.save();
                    this.afterResize(key);
                }
                this.$nextTick(() => this.drawGuides());
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', up);
        },
        // Keyboard: arrow keys on the grip add or remove a column or a row (towards the inline end grows).
        resizeKey(e, key) {
            if (this.pinned(key)) return;
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

        // ---- sorting (SortableJS, fetched the first time someone customises) ----
        async enableSorting() {
            let Sortable;
            try {
                Sortable = await loadSortable();
            } catch (error) {
                console.error('Dashboard: could not load drag and drop', error);

                return;
            }
            if (! this.$refs.grid?.isConnected) return;
            sortable ??= Sortable.create(this.$refs.grid, {
                disabled: true,
                animation: reducedMotion() ? 0 : 200,
                easing: EASE,
                draggable: '.hc-tile:not(.pinned)',
                filter: '.hc-tbtn, .hc-grip',
                preventOnFilter: false,
                delayOnTouchOnly: true,
                delay: 120,
                // Our own drag image follows the pointer exactly (the browser's native
                // drag image flickers), and a tile only swaps once the pointer is well
                // inside its neighbour, so tiles of different sizes do not flip back
                // and forth under the pointer.
                forceFallback: true,
                fallbackOnBody: true,
                fallbackTolerance: 4,
                swapThreshold: 0.6,
                invertSwap: true,
                invertedSwapThreshold: 0.6,
                ghostClass: 'hc-tile-ghost',
                chosenClass: 'hc-tile-chosen',
                dragClass: 'hc-tile-drag',
                onStart: () => {
                    this.inertGhost(Sortable.ghost);
                    this.stopWiggle();
                    this.$refs.grid.classList.add('dragging');
                },
                // Tiles are placed by number, so moving one in the list moves nothing by
                // itself: follow the new order at once, and the others glide to where they will end up.
                onChange: () => this.flip(() => this.followDom()),
                onEnd: (evt) => {
                    this.$refs.grid?.classList.remove('dragging');
                    if (evt?.item?.dataset.key) this.unplace(evt.item.dataset.key);
                    this.syncOrder();
                },
            });
            sortable.option('disabled', ! this.editing);
        },
        // The drag image is a copy of the tile, put in <body>: it must not start a
        // second copy of the tile's Livewire component or Alpine data, nor repeat its ids.
        inertGhost(ghost) {
            if (! ghost) return;
            ghost.setAttribute('x-ignore', '');
            ghost.removeAttribute('wire:key');
            ghost.querySelectorAll('*').forEach((el) => {
                ['wire:id', 'wire:snapshot', 'wire:effects', 'wire:key', 'id', 'x-data', 'x-init'].forEach((name) => el.removeAttribute(name));
            });
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
        flip(mutate, resizedKey = null) {
            const visible = () => this.tiles().filter((t) => t.offsetParent !== null);
            const before = new Map(visible().map((t) => [t, t.getBoundingClientRect()]));
            // The card being resized: where its body is drawn right now (mid-glide included).
            const resizedBody = resizedKey ? this.tileEl(resizedKey)?.querySelector(':scope > .hc-tile-body') : null;
            const bodyBefore = resizedBody?.getBoundingClientRect();
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
                if (resizedBody && bodyBefore) this.glideSize(resizedBody, bodyBefore);
                const entering = [];
                for (const t of tiles) {
                    const a = before.get(t);
                    const b = t.getBoundingClientRect();
                    let anim = null;
                    if (! a) {
                        // Shown now (a category picked, My Layout, Add): it comes in like on arrival.
                        entering.push(t);
                    } else if (t.dataset.key !== resizedKey) {
                        // The tile being resized grows or shrinks in place (glideSize); the others glide aside.
                        const dx = a.left - b.left;
                        const dy = a.top - b.top;
                        if (Math.abs(dx) >= 1 || Math.abs(dy) >= 1) {
                            // Added on top of an entrance still running, so neither cuts the other short.
                        anim = t.animate([{ transform: `translate3d(${dx}px, ${dy}px, 0)` }, { transform: 'translate3d(0, 0, 0)' }], { duration: 240, easing: EASE, composite: 'add' });
                        }
                    }
                    if (anim) {
                        glides.set(t, anim);
                        anim.onfinish = () => {
                            if (glides.get(t) === anim) glides.delete(t);
                        };
                    }
                }
                this.enter(entering, 700);
            });
        },
        // Cards coming in: faded in from the inline end with a long ease-out, one after another in
        // reading order (row by row, the start side first), the Sutask reference's motion.
        // On a return to the dashboard the same motion is shorter and quieter (reveal()).
        enter(tiles, duration = 1000, { from: distance = 96, step = 80, cap = 640 } = {}) {
            if (! tiles.length || reducedMotion()) return;
            const from = (this.metrics().rtl ? -1 : 1) * distance;
            const place = (t) => this.packed[t.dataset.key] || { x: 0, y: 0 };
            const order = tiles.filter((t) => ! this.parked(t.dataset.key))
                .sort((a, b) => place(a).y - place(b).y || place(a).x - place(b).x);
            order.forEach((t, n) => {
                entrances.get(t)?.cancel();
                const anim = t.animate(
                    [{ opacity: 0, transform: `translate3d(${from}px, 0, 0)` }, { opacity: 1, transform: 'translate3d(0, 0, 0)' }],
                    { duration, delay: Math.min(cap, n * step), easing: ENTER, fill: 'backwards' },
                );
                entrances.set(t, anim);
                anim.onfinish = () => {
                    if (entrances.get(t) === anim) entrances.delete(t);
                };
            });
        },
        // Opening the dashboard: the cards stay hidden (.hc-grid:not(.is-in)) until the layout is
        // final, so nothing flashes at its server place, jumps, or reshuffles under the motion: the
        // lazy cards have arrived (or 450ms have passed) and the empty ones have closed. Then they come in.
        // Coming back in the same tab, the empty cards are remembered (recall()): no wait, short entrance.
        async reveal() {
            if (! this.$refs.grid) {
                this.revealed = true;

                return;
            }
            const t0 = performance.now();
            // Back in this tab: what was empty is known, so no waiting and a quick, quiet entrance.
            const memory = recall();
            if (memory) {
                this.empty = memory.empty;
                this.fit = memory.fit;
            }
            // A frame, or 50ms when frames are paused (a page opened in the background).
            const tick = () => new Promise((r) => {
                requestAnimationFrame(r);
                setTimeout(r, 50);
            });
            // Cards waiting in the strip never load (they are off screen): not waited for.
            const loading = () => this.tiles().some((t) => this.shownHere(t.dataset.key) && ! this.parked(t.dataset.key)
                && t.querySelector(':scope > .hc-tile-body [data-state="loading"], :scope > .hc-tile-body [aria-busy="true"]'));
            while (! memory && loading() && performance.now() - t0 < 450) await tick();
            this.readEmpty();
            await tick();
            const shown = this.tiles().filter((t) => {
                const k = t.dataset.key;

                return this.shownHere(k) && ! this.closed(k) && ! this.absent.includes(k);
            });
            if (memory) this.enter(shown, 450, { from: 24, step: 40, cap: 280 });
            else this.enter(shown);
            // State, not a class set by hand: a server re-render (Add, Reset) rewrites the grid's
            // attributes and would drop it, hiding every card again.
            this.revealed = true;
        },

        // A card that changed size: its far edges travel from where they were drawn
        // to where they are now, so the content reflows smoothly instead of jumping a
        // cell. Only the body's insets move (no scaling: text never stretches).
        glideSize(body, from) {
            body.getAnimations().forEach((a) => a.cancel());
            const to = body.getBoundingClientRect();
            const rtl = getComputedStyle(body).direction === 'rtl';
            // From the tile's own start corner: the far edge was this much further in (or out).
            const top = from.top - to.top;
            const start = rtl ? to.right - from.right : from.left - to.left;
            const end = rtl ? from.left - to.left : to.right - from.right;
            const bottom = to.bottom - from.bottom;
            if ([top, start, end, bottom].every((v) => Math.abs(v) < 1)) return;
            body.animate([
                { insetBlockStart: top + 'px', insetInlineStart: start + 'px', insetInlineEnd: end + 'px', insetBlockEnd: bottom + 'px' },
                { insetBlockStart: '0px', insetInlineStart: '0px', insetInlineEnd: '0px', insetBlockEnd: '0px' },
            ], { duration: 300, easing: SETTLE });
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
            return JSON.parse(JSON.stringify(this.items)).map(({ key, size, pinned, hidden, style, fixed, min, at }) => ({
                key,
                size,
                pinned,
                hidden,
                ...(style === undefined ? {} : { style }),
                ...(fixed ? { fixed: true } : {}),
                ...(! hidden && Array.isArray(at) ? { at } : {}),
                ...(min && hidden ? { min: true } : {}),
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
            // The page's own $wire: a save can start from outside the page (the toast's Undo).
            inflight = rootWire.saveLayout(this.payload())
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
