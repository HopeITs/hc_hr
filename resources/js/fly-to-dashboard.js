/**
 * "Add to dashboard" flies into the dashboard (2026-11-09): what was pressed leaves its place
 * along an arc, shrinks to the size of the sidebar's dashboard icon and disappears into it;
 * the icon takes the hit (a small springy knock in the direction it came from) and a ring
 * ripples out from it. Plain Web Animations, no library.
 *
 * window.hcFlyToDashboard(element). The target is the sidebar's dashboard link (or, on a phone,
 * the bottom bar's). A page shown inside the dashboard (a card, the workspace) has no sidebar of
 * its own: it flies out of its frame into the dashboard's. With reduced motion, only the ring.
 */

const DURATION = 585; // 30% slower than the reference's 450ms (2026-11-09)
// The reference's curve: slow to leave, quick to land.
const EASE = 'cubic-bezier(0.74, 0.18, 0.93, 0.69)';
const STRENGTH = 0.5; // how far the arc bows out, as a share of the distance
const PEAK = 0.15; // how much it lifts first, as a share of the distance
const TURN = 0.9; // how far it turns on the way (radians, at most)
const STEPS = 28;

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const visible = (el) => {
    if (! el) return false;
    const r = el.getBoundingClientRect();

    return r.width > 0 && r.height > 0 && el.ownerDocument.defaultView.getComputedStyle(el).visibility !== 'hidden';
};

/** The dashboard's link in that window: the sidebar's, else the phone bar's. */
const targetIn = (win) => {
    const doc = win.document;
    const side = doc.querySelector('a[data-nav="nav.my-space.home"]');
    if (visible(side)) return side;
    const home = doc.querySelector('[wire\\:name="layout.bottom-nav"] a[href]');
    const candidates = home ? [...doc.querySelectorAll('[wire\\:name="layout.bottom-nav"] a[href]')] : [];
    const dash = candidates.find((a) => /^\/[a-z]{2}\/?$/i.test(new URL(a.href, win.location.href).pathname));

    return visible(dash) ? dash : null;
};

/** Where to draw: this window, or the dashboard around this page's frame. */
const stageFor = () => {
    let win = window;
    let dx = 0;
    let dy = 0;
    try {
        while (win !== win.parent && win.frameElement) {
            const frame = win.frameElement.getBoundingClientRect();
            dx += frame.left;
            dy += frame.top;
            win = win.parent;
            void win.document; // same site, or stop here
        }
    } catch (e) {
        return { win: window, dx: 0, dy: 0 };
    }

    return { win, dx, dy };
};

/** A copy to fly: same look, but inert (no ids, no Alpine or Livewire wiring). */
const ghostOf = (source, doc) => {
    const ghost = doc.importNode(source, true);
    const strip = (el) => {
        [...el.attributes].forEach((a) => {
            if (a.name === 'id' || a.name.startsWith('x-') || a.name.startsWith('wire:') || a.name.startsWith(':') || a.name.startsWith('@')) {
                el.removeAttribute(a.name);
            }
        });
        [...el.children].forEach(strip);
    };
    strip(ghost);
    ghost.setAttribute('aria-hidden', 'true');
    ghost.setAttribute('tabindex', '-1');

    return ghost;
};

const ring = (target) => {
    const doc = target.ownerDocument;
    const r = target.getBoundingClientRect();
    const el = doc.createElement('div');
    const radius = doc.defaultView.getComputedStyle(target).borderRadius || '9999px';
    el.style.cssText = `position:fixed;left:${r.left}px;top:${r.top}px;width:${r.width}px;height:${r.height}px;`
        + `border:1.5px solid var(--color-ink);border-radius:${radius};pointer-events:none;z-index:9999;opacity:0;`;
    doc.body.append(el);
    const anim = el.animate(
        [{ transform: 'scale(1)', opacity: 0.8 }, { transform: 'scale(2.2)', opacity: 0 }],
        { duration: 650, easing: 'ease-out' },
    );
    anim.onfinish = () => el.remove();
    anim.oncancel = () => el.remove();
};

// The icon takes the hit and springs back: out, past rest, a little out again, still.
const knock = (target, vx, vy) => {
    const len = Math.hypot(vx, vy) || 1;
    const kx = (vx / len) * 7;
    const ky = (vy / len) * 7;
    target.animate(
        [
            { translate: '0 0' },
            { translate: `${kx}px ${ky}px`, offset: 0.18 },
            { translate: `${-kx * 0.45}px ${-ky * 0.45}px`, offset: 0.45 },
            { translate: `${kx * 0.18}px ${ky * 0.18}px`, offset: 0.7 },
            { translate: '0 0' },
        ],
        { duration: 676, easing: 'ease-out' },
    );
};

export function flyToDashboard(source) {
    if (! source?.getBoundingClientRect) return;
    const { win, dx, dy } = stageFor();
    const target = targetIn(win);
    if (! target) return;
    if (reduced()) {
        ring(target);

        return;
    }

    const doc = win.document;
    const s = source.getBoundingClientRect();
    const from = { left: s.left + dx, top: s.top + dy, width: s.width, height: s.height };
    const to = target.getBoundingClientRect();
    const x = to.left + to.width / 2 - (from.left + from.width / 2);
    const y = to.top + to.height / 2 - (from.top + from.height / 2);
    const dist = Math.hypot(x, y) || 1;
    const scaleTo = Math.min(to.width / Math.max(from.width, 1), to.height / Math.max(from.height, 1));

    // A bowed path (clockwise): the control point sits off the straight line, lifted first.
    const nx = -y / dist;
    const ny = x / dist;
    const cx = x / 2 + nx * dist * STRENGTH * 0.5;
    // Lifted, but never past the top of the window (a long flight to an icon near the top would).
    const startY = from.top + from.height / 2;
    const cy = Math.max(y / 2 + ny * dist * STRENGTH * 0.5 - dist * PEAK, 24 - startY + from.height / 2);
    const at = (t) => ({
        x: 2 * (1 - t) * t * cx + t * t * x,
        y: 2 * (1 - t) * t * cy + t * t * y,
    });

    const frames = [];
    for (let i = 0; i <= STEPS; i++) {
        const t = i / STEPS;
        const p = at(t);
        const scale = 1 + (scaleTo - 1) * t;
        const turn = (TURN * 180 / Math.PI) * Math.sin(Math.PI * t) * 0.25;
        frames.push({
            offset: t,
            transform: `translate(${p.x}px, ${p.y}px) scale(${scale}) rotate(${turn}deg)`,
            opacity: t < 0.95 ? 1 : 1 - (t - 0.95) / 0.05,
        });
    }

    const ghost = ghostOf(source, doc);
    ghost.style.cssText += `;position:fixed;left:${from.left}px;top:${from.top}px;width:${from.width}px;height:${from.height}px;`
        + 'margin:0;box-sizing:border-box;pointer-events:none;z-index:9999;transform-origin:center;will-change:transform,opacity;';
    doc.body.append(ghost);

    const flight = ghost.animate(frames, { duration: DURATION, easing: EASE, fill: 'forwards' });
    flight.onfinish = () => {
        ghost.remove();
        const end = at(1);
        const before = at(0.94);
        knock(target, end.x - before.x, end.y - before.y);
        ring(target);
    };
    flight.oncancel = () => ghost.remove();
}

window.hcFlyToDashboard = flyToDashboard;
