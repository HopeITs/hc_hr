/*!
 * custom-scrollbar.js — overlay custom scrollbar (drop-in, zero dependencies)
 * ---------------------------------------------------------------------------
 * Draws a thin outlined track + centered pill thumb over every scrollable
 * container on the page, hiding the native scrollbar. Works in any project:
 * it auto-detects elements whose computed overflow-y is auto/scroll, so no
 * project-specific selectors are needed. RTL/LTR aware (offset side flips).
 *
 * USAGE
 *   <script src="custom-scrollbar.js" defer></script>
 *   (optional) set window.CustomScrollbarConfig = {...} BEFORE the tag to tune it.
 *
 * THEMING (pure CSS, no JS needed) — override the colour anywhere:
 *   :root { --csb-color: #bcbec0; --csb-hover: #a7a9ac; }
 *
 * API
 *   CustomScrollbar.refresh()      re-scan + reposition (call after big DOM changes)
 *   CustomScrollbar.attach(el)     force-attach to a specific element
 *   CustomScrollbar.config         the resolved config object
 *
 * CSP: load it as an external file (script-src 'self'); it injects one <style>
 * (needs style-src 'self' 'unsafe-inline', or add the style's hash/nonce).
 */
(function () {
  "use strict";

  /* ---------- Config (override via window.CustomScrollbarConfig before load) ---------- */
  var DEFAULTS = {
    color:       "#bcbec0", // thumb + track outline (also settable via --csb-color)
    hoverColor:  "#a7a9ac", // thumb on hover/drag   (also settable via --csb-hover)
    trackWidth:  8,         // overlay / track width (px)
    thumbWidth:  3,         // thumb width (px)
    trackBorder: 1,         // track outline thickness (px)
    trackRadius: 4,         // track corner radius (px)
    thumbRadius: 3,         // thumb corner radius (px)
    offset:      5,         // distance from the container edge (px) — left in RTL, right in LTR
    padY:        10,        // empty margin at the TOP of the track (px)
    padBottom:   null,      // empty margin at the BOTTOM (px) — null = same as padY (bottom safety)
    thumbGap:    2,         // gap between the thumb and each track end (px)
    minThumb:    24,        // minimum thumb length (px)
    zIndex:      "auto",    // overlay stacking — "auto" derives it from the container (see overlayZ), a number pins it
    headerSelector: null,   // CSS selector of a fixed/sticky page header the overlay must not paint over
    exclude:     []         // extra CSS selectors to skip, e.g. [".my-popup", "#chat"]
  };
  var C = {}, over = window.CustomScrollbarConfig || {};
  for (var k in DEFAULTS) C[k] = (over[k] !== undefined ? over[k] : DEFAULTS[k]);

  var OFFSET = C.offset, TRACKW = C.trackWidth, MIN = C.minThumb, PAD = C.padY, TPAD = C.thumbGap;
  var PADB = (C.padBottom == null ? PAD : C.padBottom);
  var HEADER_SEL = C.headerSelector;

  /* Bottom of a fixed/sticky page header, in viewport coords — the overlay is
     clipped so it never paints above it (e.g. an internal card scrollbar must
     not ride over the top navbar when the whole page scrolls). 0 when none. */
  function headerInset() {
    if (!HEADER_SEL) return 0;
    var h = document.querySelector(HEADER_SEL);
    if (!h) return 0;
    var pos = getComputedStyle(h).position;
    if (pos !== "fixed" && pos !== "sticky") return 0;
    var hr = h.getBoundingClientRect();
    return (hr.width > 0 && hr.height > 0) ? hr.bottom : 0;
  }

  /* z-index at which `node` competes in the root stacking context — the one
     the overlay lives in, being a fixed child of <body>. That is the z-index of
     its outermost stacking-context ancestor (0 when that one is z:auto). */
  function stackLevel(node) {
    var level = 0;
    for (var n = node; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
      var cs = getComputedStyle(n), z = cs.zIndex, pos = cs.position;
      var pd = n.parentElement ? getComputedStyle(n.parentElement).display : "";
      var flexItem = pd.indexOf("flex") > -1 || pd.indexOf("grid") > -1;
      var zSet = z !== "auto" && (pos !== "static" || flexItem);
      if (zSet || pos === "fixed" || pos === "sticky" || parseFloat(cs.opacity) < 1 ||
          cs.transform !== "none" || cs.filter !== "none" || cs.isolation === "isolate") {
        level = zSet ? (parseInt(z, 10) || 0) : 0;
      }
    }
    return level;
  }

  /* Overlay z-index: just above its own container, so it paints over the
     container (and over a modal/sidebar it lives in), but never over layers
     that sit above that container — navbar menus, page dropdowns, the tour.
     Page-level containers are lifted to just under the header, which keeps
     the bar above in-page sticky headers (z-10/z-20) while menus hanging
     from the header still cover it. */
  function overlayZ(el) {
    if (C.zIndex !== "auto") return C.zIndex;
    var z = stackLevel(el) + 1;
    var h = HEADER_SEL && document.querySelector(HEADER_SEL);
    if (h) { var hz = stackLevel(h); if (z < hz) z = Math.max(z, hz - 1); }
    return z;
  }
  var attached = new WeakSet(), seen = new WeakSet(), instances = [];

  /* ---------- Inject CSS (colours via CSS vars so the host can re-theme in plain CSS) ---------- */
  function injectCSS() {
    if (document.getElementById("csb-style")) return;
    var css =
      ".has-csb{scrollbar-width:none;}" +
      ".has-csb::-webkit-scrollbar{width:0;height:0;display:none;}" +
      ".csb{position:fixed;width:" + TRACKW + "px;pointer-events:none;opacity:0;transition:opacity .18s;}" +
      ".csb.on{opacity:1;}" +
      ".csb-track{position:absolute;inset:0;border:" + C.trackBorder + "px solid var(--csb-color," + C.color + ");border-radius:" + C.trackRadius + "px;box-sizing:border-box;pointer-events:auto;}" +
      ".csb-thumb{position:absolute;left:50%;transform:translateX(-50%);width:" + C.thumbWidth + "px;background:var(--csb-color," + C.color + ");border-radius:" + C.thumbRadius + "px;pointer-events:auto;}" +
      ".csb-thumb:hover,.csb-thumb.csb-drag{background:var(--csb-hover," + C.hoverColor + ");}";
    var st = document.createElement("style");
    st.id = "csb-style";
    st.textContent = css;
    (document.head || document.documentElement).appendChild(st);
  }

  function excluded(el) {
    if (el.classList && el.classList.contains("csb")) return true;
    for (var i = 0; i < C.exclude.length; i++) {
      try { if (el.matches(C.exclude[i])) return true; } catch (e) {}
    }
    return false;
  }

  /* ---------- One overlay scrollbar bound to a scroll container ---------- */
  function CSB(el) {
    var wrap = document.createElement("div"); wrap.className = "csb";
    var track = document.createElement("div"); track.className = "csb-track";
    var thumb = document.createElement("div"); thumb.className = "csb-thumb";
    wrap.appendChild(track); wrap.appendChild(thumb); document.body.appendChild(wrap);
    el.classList.add("has-csb");

    function update() {
      if (!document.body.contains(el)) { wrap.classList.remove("on"); return; }
      var r = el.getBoundingClientRect(), ch = el.clientHeight, sh = el.scrollHeight;
      if (r.width === 0 || r.height === 0 || sh <= ch + 1) { wrap.classList.remove("on"); return; }
      var rtl = getComputedStyle(el).direction === "rtl";
      var left = rtl ? (r.left + OFFSET) : (r.right - OFFSET - TRACKW);
      var trackH = Math.max(0, ch - PAD - PADB);   // empty PAD at top, PADB at bottom
      var innerH = Math.max(0, trackH - 2 * TPAD); // thumb travel band inside the track
      var overlayTop = r.top + el.clientTop + PAD;
      wrap.style.zIndex = overlayZ(el);
      wrap.style.left = left + "px";
      wrap.style.top = overlayTop + "px";
      wrap.style.height = trackH + "px";
      var thumbH = Math.max(MIN, innerH * ch / sh), maxS = sh - ch;
      var ratio = maxS > 0 ? el.scrollTop / maxS : 0;
      thumb.style.height = thumbH + "px";
      thumb.style.top = (TPAD + ratio * (innerH - thumbH)) + "px";

      // Clip whatever scrolls up behind a fixed page header, so the overlay
      // stays inside its container and never rides over the top navbar.
      var hiddenTop = Math.max(0, headerInset() - overlayTop);
      if (hiddenTop >= trackH) { wrap.classList.remove("on"); return; }
      wrap.style.clipPath = hiddenTop > 0 ? ("inset(" + hiddenTop + "px 0 0 0)") : "";

      wrap.classList.add("on");
    }

    el.addEventListener("scroll", update, { passive: true });
    if (window.ResizeObserver) { try { new ResizeObserver(update).observe(el); } catch (e) {} }
    try { new MutationObserver(update).observe(el, { childList: true, subtree: true, characterData: true }); } catch (e) {}

    thumb.addEventListener("mousedown", function (e) {
      e.preventDefault(); e.stopPropagation();
      var sy = e.clientY, ss = el.scrollTop, sh = el.scrollHeight, th = thumb.offsetHeight,
          maxS = sh - el.clientHeight, trackH = wrap.offsetHeight;
      thumb.classList.add("csb-drag");
      function mv(ev) { var range = trackH - 2 * TPAD - th; el.scrollTop = ss + (range > 0 ? ((ev.clientY - sy) / range) * maxS : 0); }
      function up() { document.removeEventListener("mousemove", mv); document.removeEventListener("mouseup", up); thumb.classList.remove("csb-drag"); }
      document.addEventListener("mousemove", mv); document.addEventListener("mouseup", up);
    });

    track.addEventListener("mousedown", function (e) {
      if (e.target === thumb) return;
      var wr = wrap.getBoundingClientRect(), th = thumb.offsetHeight;
      var t = ((e.clientY - wr.top - TPAD) - th / 2) / (wr.height - 2 * TPAD - th);
      t = Math.max(0, Math.min(1, t));
      el.scrollTop = t * (el.scrollHeight - el.clientHeight);
    });

    instances.push(update); update();
  }

  /* ---------- Generic detection: any vertically-scrollable element gets a bar ---------- */
  function scan() {
    var all = document.getElementsByTagName("*");
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      if (seen.has(el)) continue;
      seen.add(el);
      if (el.namespaceURI && el.namespaceURI.indexOf("svg") > -1) continue;      // skip SVG nodes
      var tag = el.tagName;
      if (tag === "TEXTAREA" || tag === "INPUT" || tag === "SELECT" || tag === "HTML" || tag === "BODY") continue;
      if (excluded(el)) continue;
      var oy = getComputedStyle(el).overflowY;
      if ((oy === "auto" || oy === "scroll") && !attached.has(el)) { attached.add(el); CSB(el); }
    }
  }

  var raf = null;
  function schedule() {
    if (raf) return;
    raf = requestAnimationFrame(function () { raf = null; scan(); for (var i = 0; i < instances.length; i++) instances[i](); });
  }

  window.addEventListener("resize", schedule, { passive: true });
  window.addEventListener("scroll", schedule, { passive: true, capture: true });

  function boot() {
    injectCSS();
    scan();
    for (var i = 0; i < instances.length; i++) instances[i]();
    /* childList only, to find new scroll containers; show/hide is caught by each
       element's ResizeObserver. Watching attributes here would loop, because
       updating the .csb overlays changes their style and re-fires the observer. */
    if (document.body) { try { new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true }); } catch (e) {} }
  }

  if (document.readyState !== "loading") boot(); else document.addEventListener("DOMContentLoaded", boot);
  window.addEventListener("load", schedule);

  window.CustomScrollbar = {
    refresh: schedule,
    attach: function (el) { if (el && !attached.has(el)) { attached.add(el); seen.add(el); injectCSS(); CSB(el); } },
    config: C
  };
})();
