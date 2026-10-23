# A7 · Remove artificial page-covering navigation delays: implementation notes

Status: **implemented and verified, awaiting review** (QA engineer + technical lead). Not marked done until reviewed.
Date: 2026-10-21. Task: A7 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-01). Consumer: G-A.

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision | `hr-ux-by-rafi` @ `990f4aac` (= `main` with A3-R, plus B0) |
| X1 | Measured ([performance-baseline.md](performance-baseline.md) §4 bottleneck 4: the overlay hides rendered content 0.55–1.25 s on desktop, 1.8–4.3 s on the phone profile) |
| X2 | Instruments ready, **0 participant sessions**. A7 proceeds under option **(b)** of [usability-baseline.md](usability-baseline.md) §10: **there is no pre-change usability baseline for this change.** X2-R must disclose it. The technical lead has not yet recorded (a)/(b) in writing; A7 was started on the developer's instruction. |
| B0 | Implemented and verified ([feedback-contract.md](feedback-contract.md)) |

What was on screen before A7 (`resources/views/components/layouts/app.blade.php`): a server-rendered, **visible** full-screen white layer `#page-gif-overlay` (z-index 9999) with a looping MP4 (`public/assets/GIF/hr-website-2.mp4`), on **every** full page load and **every** `wire:navigate`. An inline script hid it only after `MIN_VISIBLE = 300` ms plus a `FADE = 250` ms fade, whether or not the page was ready. `87e31bfa` had shortened this from 1.5 s; A7 finishes the job.

## 2. What changed

| File | Change | Why |
|---|---|---|
| `resources/views/components/layouts/app.blade.php` | Removed `#page-gif-overlay` (the `<video>`) and the inline script that held it open | AC-A7-01: no fixed minimum delay, no decorative layer over ready content |
| `resources/js/product-tour.js` | Removed `preloaderGone()`; `autostart()` now opens the tour after a 300 ms layout settle instead of polling for the overlay | The tour waited for the overlay; with no overlay that wait is dead code |
| `resources/css/app.css` | Under `prefers-reduced-motion: reduce`, Livewire's progress bar (`#nprogress .bar`) no longer slides | AC-A7-03: keep the progress information, drop the motion |
| `tests/Feature/AccessibilityTest.php` | The assertion "the preloader is `aria-hidden`" became "the layout has no `page-gif-overlay` and no `<video>`" | **Expectation change, documented:** the old intent (keep the decorative layer away from assistive technology) is met more completely by removing it; the new assertion guards A7's rule |

**Progress that remains (task step 2):**
- `wire:navigate`: Livewire's navigate progress bar, already enabled (`config/livewire.php` `navigate.show_progress_bar = true`, brand colour via `--livewire-progress-bar-color`).
- Full page loads: the browser's own loading indicator.
- Actions: each control's own pending state (`wire:loading`, the primary button's spinner), unchanged.

**Not changed, noted:**
- `public/assets/GIF/hr-website-2.mp4` is no longer referenced. It was left in place (deleting public assets is outside A7; it can be removed in a clean-up).
- `resources/views/components/layouts/app/page-loader.blade.php` is an older full-screen spinner overlay that **no view uses**. Left untouched; it must not be re-introduced.
- No welcome animation was added (task step 3).

## 3. Before/after measurement (task step 4)

X1's harness (`tests/Performance/Baseline/measure.py`) needs Python Playwright, which is **not installed** on this machine (installing it downloads packages and a browser, which needs separate approval). The measurement was therefore taken in the Claude desktop built-in browser, with **X1's definition**: first useful content = the later of "content in place" and "overlay gone". Raw data: [evidence/a7/navigation-overlay.json](evidence/a7/navigation-overlay.json).

Set-up: X2 session copy (synthetic SQLite, HR-ALP persona), `php -S` single worker, warm, desktop pane, 5 rounds of `/employees → /contracts → /` by `wire:navigate`, each navigation started only after the network had been quiet for 700 ms. Before = A7 changes stashed and assets rebuilt; after = A7 applied and rebuilt.

**Time that ready content stayed hidden behind the overlay** (overlay gone − content in place):

| Navigation | Before: median (range), 5 runs | After |
|---|---|---|
| → Employees | **458 ms** (426–471) | **0 ms** |
| → Contracts | **453 ms** (437–476) | **0 ms** |
| → Dashboard | **714 ms** (577–870) | **0 ms** |

**Absolute load times are not reported as an improvement.** On this machine they swing between runs with no code change (Employees content: 1022 ms median in one before run, 1607 ms in another), so they cannot isolate A7's effect. The hidden time is measured within each navigation and is not affected by that swing.

**Disclosed measurement error:** a first after-run used a fixed 400 ms gap between navigations and looked **slower** (Contracts ≈ 2.8 s). Cause: without the overlay's extra half second, the next navigation started while the previous page's employee photos were still loading, and queued behind them on the single-worker dev server. The settled method above removes that artefact; both runs are kept in the evidence file.

**Full page loads:** the same overlay covered every full load; it is gone from the layout, so no full load is covered either (verified: no `#page-gif-overlay` or `<video>` in the rendered page). The phone profile, where X1 measured 1.8–4.3 s of hidden content, was not re-measured; with the element removed, that hidden time is structurally zero.

## 4. Behaviour checks (built-in browser, 2026-10-21)

| Check | Result |
|---|---|
| Overlay in the rendered page (EN `/`, AR `/ar/employees`, `/ar/contracts`) | **absent**; no `<video>` |
| Progress during `wire:navigate` (dashboard → contracts) | `#nprogress` bar appears (fixed, top), removed after navigation |
| Input during navigation | Nothing covers the page; the element at the centre of the screen is page content |
| Failed navigation (`/contracts/999999`) | 404 page shown; no overlay; progress bar removed |
| Network failure mid-navigation (session server stopped, then `wire:navigate` to `/employees`) | Page stays on the dashboard and stays usable; no overlay; progress bar removed. **No message tells the user the navigation failed** (see §6) |
| Reduced motion | Rule present in the compiled CSS (`@media (prefers-reduced-motion:reduce){… #nprogress .bar{transition:none!important}}`). The OS setting itself could not be emulated in this browser. |
| Product tour auto-start without the overlay wait | Cleared the "seen" marks, reloaded the dashboard: tour opened ("Step 1 of 8") |
| Arabic `wire:navigate` (`/ar/employees` → `/ar/contracts`) | Navigates, `dir="rtl"`, 15 rows, no overlay |

## 5. Tests

| Run | Result |
|---|---|
| Full suite (`vendor/bin/phpunit`, PHP 8.5 + `pdo_sqlite`) | **609 tests, 604 passed, 5 failed**: the same five pre-existing failures as B0 (A1 register #6, #11, #12, #13, and `ContractPdfRendererTest::test_a_remote_chrome_is_served_the_page_without_a_public_url` for A6). `AccessibilityTest` (with the updated assertion) passes. No new failure. |

## 6. Limitations and hand-offs

- **No usability baseline** (option (b), §1). The technical lead should record (b) in writing; X2-R must state that A7 has no pre-change user baseline.
- **Silent failed navigation:** when the network drops mid-navigation, the click does nothing visible. That error-and-retry message belongs to **B3** (loading, empty and retry states).
- **X1-I:** when X1's harness is re-run (needs Playwright), `measure.py`'s `overlay_hidden` mark will fire immediately (no overlay). Its "first useful" then equals content-visible, which is the intended comparison; no harness change is needed.
- **Real screen reader / phone run** not done for A7 specifically; the change removes an `aria-hidden` element, so assistive-technology impact is nil, and the phone layout is unchanged apart from the missing overlay.
- **B0 document corrected:** a note about locale-prefixed URLs and `wire:navigate` in [feedback-contract.md](feedback-contract.md) §7 was inaccurate and is fixed (the app's Arabic links carry `/ar/…` and navigate normally).

## 7. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-A7-01** No fixed minimum delay or decorative overlay hides ready content. | **Met** | §2 removal; §3 hidden time 458/453/714 ms → 0 ms; §4 no overlay in EN/AR pages; `AccessibilityTest` guard |
| **AC-A7-02** Dashboard → employees → contracts navigation exposes clear progress without trapping input. | **Met** | §4: `#nprogress` progress bar during navigation; nothing covers the page; 15 navigations per run in §3 |
| **AC-A7-03** Slow/error navigation and reduced-motion mode recover correctly. | **Met, with one hand-off** | §4: 404 and network-failure cases leave a usable page with no stranded overlay or bar; reduced-motion rule compiled. The missing failure message goes to B3 (§6). |

**Formal review (QA engineer + technical lead): pending.** A7 is **not** marked done.
