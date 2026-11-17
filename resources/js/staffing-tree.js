/**
 * Team staffing canvas (x-data="staffingTree").
 *
 * x-data sits on the section that holds both the toolbar and the canvas, so
 * the toolbar's zoom / fit / reset / find controls call these methods
 * directly. The server renders the visible positions as a flat list of
 * absolutely positioned wrappers ([data-tree-node] with data-node-id /
 * data-parent-id); collapsed branches are simply not rendered. This component
 * measures every card, hands the sizes to the pure layout in
 * staffing-tree-layout.js, places the cards, draws the team boxes and the
 * connectors into one SVG and owns pan / zoom / fit / find.
 *
 * Relayout runs (debounced to one animation frame) when:
 *  - Livewire morphs this component (`morphed` hook),
 *  - wrappers are added or removed (MutationObserver on the world),
 *  - any card or the viewport changes size (ResizeObserver: images, the
 *    request menu opening, the window),
 *  - an image inside the canvas finishes loading.
 * The world, viewport and wrappers carry wire:ignore.self, so a morph never
 * strips the transforms set here.
 *
 * Until the viewer pans or zooms, every relayout fits the view again (cards
 * and images settle, the window resizes). After a toolbar action (expand /
 * collapse all, unfilled only, view switch) it is fitted again regardless;
 * after a branch toggle the toggled card stays where it was on screen.
 *
 * Fit shows the whole tree when that keeps the cards at FIT_MIN or more. When
 * it does not, it frames as many levels from the top as fit at that size, root
 * at the top, and the viewer pans to the rest.
 */
import { layoutTree } from './staffing-tree-layout.js';

const MIN_SCALE = 0.25;
const MAX_SCALE = 1.5;
const FIT_MIN = 0.6;
const FIT_MAX = 1;
const PAD = 32; // world padding around the tree
const MARGIN = 16; // space kept between the tree and the viewport edge
const REFIT_ON = ['expandAll', 'collapseAll', 'toggleVacantOnly', 'setView'];

const normalise = (s) => String(s || '')
    .toLocaleLowerCase()
    .normalize('NFKD')
    // Latin accents, Arabic harakat / hamza marks / dagger alef, tatweel. NFKD
    // splits أ إ آ into ا plus a mark, so dropping the marks folds them to ا.
    .replace(/[\u0300-\u036f\u064b-\u0655\u0670\u0640]/g, '')
    .replace(/[أإآ]/g, 'ا')
    .replace(/ى/g, 'ي')
    .replace(/ة/g, 'ه')
    .replace(/\s+/g, ' ')
    .trim();

function staffingTree() {
    return {
        scale: 1,
        tx: 0,
        ty: 0,
        query: '',
        matchCount: 0,
        matchIndex: -1,
        searched: false,
        ready: false,

        _layout: null,
        _frame: 0,
        _fitPending: true,
        _userMoved: false,
        _anchor: null,
        _drag: null,
        _observed: new Set(),
        _cleanups: [],

        init() {
            this._observed = new Set();
            this._cleanups = [];
            const viewport = this.$refs.viewport;
            const world = this.$refs.world;
            if (!viewport || !world) return;

            this._ro = new ResizeObserver(() => this.schedule());
            this._ro.observe(viewport);
            this._observeNodes();

            this._mo = new MutationObserver(() => {
                this._observeNodes();
                this.schedule();
            });
            this._mo.observe(world, { childList: true });

            const onLoad = () => this.schedule();
            world.addEventListener('load', onLoad, true);
            const onWheel = (e) => this.onWheel(e);
            viewport.addEventListener('wheel', onWheel, { passive: false });
            this._cleanups.push(() => {
                world.removeEventListener('load', onLoad, true);
                viewport.removeEventListener('wheel', onWheel);
            });

            // Keep the toggled card under the pointer instead of jumping.
            const onClick = (e) => {
                const toggle = e.target.closest('[data-tree-toggle]');
                if (!toggle) return;
                const wrapper = toggle.closest('[data-tree-node]');
                if (wrapper) this._rememberAnchor(wrapper.dataset.nodeId);
            };
            world.addEventListener('click', onClick, true);
            this._cleanups.push(() => world.removeEventListener('click', onClick, true));

            // Refit after the toolbar actions that reshape the whole tree.
            //
            // Livewire 4 runs the legacy `commit` hook's succeed() callbacks in
            // the animation frame *after* the morph — after the relayout the
            // morph had scheduled already ran — so the refit flag landed too
            // late and nothing laid out again. A message interceptor's
            // onMorphed runs synchronously right after the morph, before it.
            const componentId = this.$root.closest('[wire\\:id]')?.getAttribute('wire:id');
            const hook = (Livewire) => {
                if (typeof Livewire.interceptMessage === 'function') {
                    this._cleanups.push(Livewire.interceptMessage(({ message, onSuccess }) => {
                        if (message?.component?.id !== componentId) return;
                        const actions = Array.from(message.actions || []);
                        if (!actions.some((action) => REFIT_ON.includes(action.name))) return;
                        onSuccess(({ onMorphed }) => onMorphed(() => this.refitSoon()));
                    }));
                }
                this._cleanups.push(Livewire.hook('morphed', ({ component }) => {
                    if (component?.id === componentId) this.schedule();
                }));
            };
            if (window.Livewire) hook(window.Livewire);
            else document.addEventListener('livewire:init', () => hook(window.Livewire), { once: true });

            this.schedule();
        },

        destroy() {
            cancelAnimationFrame(this._frame);
            this._ro?.disconnect();
            this._mo?.disconnect();
            this._cleanups.forEach((fn) => typeof fn === 'function' && fn());
        },

        refitSoon() {
            this._fitPending = true;
            this._anchor = null;
            this.schedule();
        },

        get percent() {
            return `${Math.round(this.scale * 100)}%`;
        },

        // ------------------------------------------------------------ layout

        _wrappers() {
            const world = this.$refs.world;
            return world ? Array.from(world.querySelectorAll(':scope > [data-tree-node]')) : [];
        },

        _observeNodes() {
            const current = new Set(this._wrappers());
            for (const el of this._observed) {
                if (!current.has(el)) {
                    this._ro.unobserve(el);
                    this._observed.delete(el);
                }
            }
            for (const el of current) {
                if (!this._observed.has(el)) {
                    this._ro.observe(el);
                    this._observed.add(el);
                }
            }
        },

        schedule() {
            cancelAnimationFrame(this._frame);
            this._frame = requestAnimationFrame(() => this.relayout());
        },

        _visible() {
            const v = this.$refs.viewport;
            // Hidden below the sm breakpoint (the outline list is shown instead).
            return !!v && v.offsetWidth > 0;
        },

        relayout() {
            if (!this._visible()) return;

            const wrappers = this._wrappers();
            const rtl = getComputedStyle(this.$refs.viewport).direction === 'rtl';
            const input = wrappers.map((el) => ({
                id: el.dataset.nodeId,
                parent: el.dataset.parentId || null,
                w: el.offsetWidth,
                h: el.offsetHeight,
            }));

            const anchorBefore = this._anchor;
            const layout = layoutTree(input, { rtl });
            this._layout = layout;

            wrappers.forEach((el) => {
                const p = layout.nodes[el.dataset.nodeId];
                if (!p) return;
                el.style.transform = `translate(${p.x + PAD}px, ${p.y + PAD}px)`;
                el.classList.remove('invisible');
            });

            const w = layout.width + PAD * 2;
            const h = layout.height + PAD * 2;
            const world = this.$refs.world;
            world.style.width = `${w}px`;
            world.style.height = `${h}px`;

            // Team boxes first (behind), then one path for every connector.
            const svg = this.$refs.links;
            svg.setAttribute('width', w);
            svg.setAttribute('height', h);
            svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
            const rects = layout.groups
                .map((g) => `<rect x="${g.x + PAD + 0.5}" y="${g.y + PAD + 0.5}" width="${Math.max(0, g.w - 1)}" height="${Math.max(0, g.h - 1)}" rx="16"/>`)
                .join('');
            const d = layout.links
                .map(([x1, y1, x2, y2]) => `M${x1 + PAD + 0.5} ${y1 + PAD + 0.5}L${x2 + PAD + 0.5} ${y2 + PAD + 0.5}`)
                .join('');
            svg.innerHTML = `<g fill="var(--color-soft)" stroke="var(--color-line-soft)" stroke-width="1">${rects}</g>`
                + `<path d="${d}" fill="none" stroke="var(--color-line)" stroke-width="1" stroke-linecap="square"/>`;

            if (this._fitPending || (!this._userMoved && !anchorBefore)) {
                this._fitPending = false;
                this._anchor = null;
                this.fit({ resizeViewport: true, user: false });
            } else if (anchorBefore) {
                this._restoreAnchor(anchorBefore);
            } else {
                this.apply();
            }

            if (this.query) this._refreshMatches(false);
            this.ready = true;
        },

        _rememberAnchor(id) {
            const p = this._layout?.nodes[id];
            if (!p) return;
            this._anchor = {
                id,
                sx: this.tx + (p.x + PAD + p.w / 2) * this.scale,
                sy: this.ty + (p.y + PAD) * this.scale,
            };
        },

        _restoreAnchor(anchor) {
            const p = this._layout?.nodes[anchor.id];
            this._anchor = null;
            this._userMoved = true;
            if (p) {
                this.tx = anchor.sx - (p.x + PAD + p.w / 2) * this.scale;
                this.ty = anchor.sy - (p.y + PAD) * this.scale;
            }
            this.apply();
        },

        // ------------------------------------------------------------- view

        apply() {
            const world = this.$refs.world;
            if (world) world.style.transform = `translate(${this.tx}px, ${this.ty}px) scale(${this.scale})`;
        },

        _rootCenterX() {
            const l = this._layout;
            const root = l && l.rootId !== null ? l.nodes[l.rootId] : null;
            return root ? root.x + PAD + root.w / 2 : (l ? l.width / 2 + PAD : 0);
        },

        /**
         * The scale and the horizontal slice of the world to frame: the whole
         * tree when it fits at FIT_MIN or more, otherwise the deepest run of
         * levels from the root that does (FIT_MIN if not even the root's own
         * level fits). x0 / x1 are world px.
         */
        _fitTarget(vw, vh) {
            const l = this._layout;
            const w = l.width + PAD * 2;
            const h = l.height + PAD * 2;
            const whole = Math.min(FIT_MAX, vw / w, vh / h);
            if (whole >= FIT_MIN || !l.levels.length) {
                return { s: Math.max(FIT_MIN, whole), x0: 0, x1: w };
            }

            let best = null;
            let x0 = Infinity;
            let x1 = -Infinity;
            l.levels.forEach((level, i) => {
                if (best === false) return;
                x0 = Math.min(x0, level.x0);
                x1 = Math.max(x1, level.x1);
                const s = Math.min(FIT_MAX, vw / (x1 - x0 + PAD * 2), vh / (level.y1 + PAD * 2));
                if (s < FIT_MIN) {
                    best = best || false;
                    return;
                }
                // The root alone is not a view of a team: only frame a slice
                // that reaches at least the direct reports.
                if (i >= 1 || l.levels.length === 1) best = { s, x0, x1: x1 + PAD * 2 };
            });
            if (best) return best;

            const top = l.levels[0];
            return { s: FIT_MIN, x0: top.x0, x1: top.x1 + PAD * 2 };
        },

        /** Fit to screen: readable cards (60%–100%), root at the top. */
        fit({ resizeViewport = false, user = true } = {}) {
            const l = this._layout;
            if (!l || !this._visible()) return;
            const viewport = this.$refs.viewport;
            if (user) this._userMoved = false;

            // Shrink the viewport to a small tree instead of leaving a tall
            // empty canvas, between its CSS minimum and maximum.
            if (resizeViewport) {
                viewport.style.height = '';
                const max = viewport.clientHeight;
                const min = parseFloat(getComputedStyle(viewport).minHeight) || 0;
                const t = this._fitTarget(viewport.clientWidth - MARGIN * 2, max - MARGIN * 2);
                const want = Math.ceil((l.height + PAD * 2) * t.s + MARGIN * 2);
                viewport.style.height = `${Math.max(min, Math.min(max, want))}px`;
            }

            const vw = viewport.clientWidth - MARGIN * 2;
            const vh = viewport.clientHeight - MARGIN * 2;
            const t = this._fitTarget(vw, vh);
            this.scale = t.s;
            // Centre the framed slice horizontally; the top of the tree (the
            // root) stays at the top.
            this.tx = MARGIN + (vw - (t.x1 - t.x0) * t.s) / 2 - t.x0 * t.s;
            this.ty = MARGIN;
            this.apply();
        },

        reset() {
            if (!this._visible()) return;
            const viewport = this.$refs.viewport;
            this._userMoved = true;
            this.scale = 1;
            this.tx = viewport.clientWidth / 2 - this._rootCenterX();
            this.ty = MARGIN;
            this.apply();
        },

        zoomAt(next, cx, cy) {
            const s = Math.min(MAX_SCALE, Math.max(MIN_SCALE, next));
            this._userMoved = true;
            const wx = (cx - this.tx) / this.scale;
            const wy = (cy - this.ty) / this.scale;
            this.scale = s;
            this.tx = cx - wx * s;
            this.ty = cy - wy * s;
            this.apply();
        },

        zoomBy(factor) {
            if (!this._visible()) return;
            const v = this.$refs.viewport;
            this.zoomAt(this.scale * factor, v.clientWidth / 2, v.clientHeight / 2);
        },

        onWheel(e) {
            // Plain wheel scrolls the page; only ctrl / cmd + wheel (and the
            // trackpad pinch, which reports as ctrl) zooms the canvas.
            if (!(e.ctrlKey || e.metaKey)) return;
            e.preventDefault();
            const rect = this.$refs.viewport.getBoundingClientRect();
            const factor = Math.exp(-e.deltaY * (e.deltaMode === 1 ? 0.05 : 0.0025));
            this.zoomAt(this.scale * factor, e.clientX - rect.left, e.clientY - rect.top);
        },

        onKeydown(e) {
            const step = 60;
            const keys = {
                ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step],
            };
            if (e.target !== this.$refs.viewport) return;
            if (keys[e.key]) {
                e.preventDefault();
                this._userMoved = true;
                this.tx += keys[e.key][0];
                this.ty += keys[e.key][1];
                this.apply();
            } else if (e.key === '+' || e.key === '=') {
                e.preventDefault();
                this.zoomBy(1.2);
            } else if (e.key === '-') {
                e.preventDefault();
                this.zoomBy(1 / 1.2);
            } else if (e.key === '0') {
                e.preventDefault();
                this.fit();
            }
        },

        // -------------------------------------------------------------- pan

        onPointerDown(e) {
            if (e.button !== 0 || this._drag) return;
            if (e.target.closest('[data-no-pan], a, button, input, select, textarea, label, summary, [role="button"]')) return;
            this._drag = { id: e.pointerId, x: e.clientX, y: e.clientY, tx: this.tx, ty: this.ty, moved: false };
            this.$refs.viewport.setPointerCapture?.(e.pointerId);
        },

        onPointerMove(e) {
            const d = this._drag;
            if (!d || d.id !== e.pointerId) return;
            const dx = e.clientX - d.x;
            const dy = e.clientY - d.y;
            if (!d.moved && Math.hypot(dx, dy) < 3) return;
            d.moved = true;
            this._userMoved = true;
            this.$refs.viewport.dataset.panning = '';
            this.tx = d.tx + dx;
            this.ty = d.ty + dy;
            this.apply();
        },

        onPointerUp(e) {
            if (!this._drag || this._drag.id !== e.pointerId) return;
            this._drag = null;
            delete this.$refs.viewport.dataset.panning;
            this.$refs.viewport.releasePointerCapture?.(e.pointerId);
        },

        // ------------------------------------------------------------- find

        _matches() {
            const q = normalise(this.query);
            if (!q) return [];
            return this._wrappers().filter((el) => normalise(el.textContent).includes(q));
        },

        _refreshMatches(move) {
            const matches = this._matches();
            this.matchCount = matches.length;
            if (!matches.length) {
                this.matchIndex = -1;
                this._highlight(null);
                return;
            }
            if (this.matchIndex < 0 || this.matchIndex >= matches.length) this.matchIndex = 0;
            this._highlight(matches[this.matchIndex], move);
        },

        search() {
            this.searched = this.query.trim() !== '';
            this.matchIndex = 0;
            this._refreshMatches(true);
        },

        findNext(step = 1) {
            const matches = this._matches();
            this.searched = this.query.trim() !== '';
            this.matchCount = matches.length;
            if (!matches.length) {
                this.matchIndex = -1;
                this._highlight(null);
                return;
            }
            this.matchIndex = ((this.matchIndex + step) % matches.length + matches.length) % matches.length;
            this._highlight(matches[this.matchIndex], true);
        },

        clearSearch() {
            this.query = '';
            this.searched = false;
            this.matchCount = 0;
            this.matchIndex = -1;
            this._highlight(null);
        },

        _highlight(el, move = false) {
            this._wrappers().forEach((w) => {
                if (w !== el) w.removeAttribute('data-match');
            });
            if (!el) return;
            el.setAttribute('data-match', '');
            if (!move || !this._visible()) return;
            const p = this._layout?.nodes[el.dataset.nodeId];
            if (!p) return;
            const v = this.$refs.viewport;
            this._userMoved = true;
            if (this.scale < FIT_MIN) this.scale = FIT_MIN;
            this.tx = v.clientWidth / 2 - (p.x + PAD + p.w / 2) * this.scale;
            this.ty = v.clientHeight / 3 - (p.y + PAD) * this.scale;
            this.apply();
        },
    };
}

const register = () => window.Alpine.data('staffingTree', staffingTree);

if (window.Alpine) register();
else document.addEventListener('alpine:init', register);
