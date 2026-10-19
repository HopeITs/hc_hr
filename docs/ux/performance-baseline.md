# X1 — Performance baseline (proposed)

Status: **measured, awaiting review** (QA engineer + technical lead). Not marked done until reviewed.
Date: 2026-10-16. Task: X1 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-01, R-02, R-23).
Consumers: A5, A4, A7, X1-I.

This document records how fast four flows are **today**, before any UX change, and ranks the bottlenecks behind the numbers. It proposes no fix. Measured data (§3–§4) and proposed targets (§6) are kept apart, as the task requires.

---

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Code measured | `main` @ `fb01a226be474820a2aba710edf0a661d294dc7a`, plus the uncommitted harness (recorded in `environment.json`). Since then `main` has gained `5726436f`, which only adds `docs/ux/decisions.md`. No measured code changed. |
| Harness | committed on `codex/x1-performance-baseline`: `c28b9de7`, `c26869e3`, `f0584960` and the commit carrying this document |
| A1 prerequisite | [test-baseline.md](test-baseline.md) is present (544 tests / 531 pass / 13 known failures). It is a draft awaiting review, like [start-state.md](start-state.md). |
| P0 limitation inherited | There is no staging host and no disposable browser-QA database. X1 builds its own synthetic SQLite database instead (§2.2). Neither the operational MySQL nor `config/samples/sampleData.php`'s people are used. |

## 2. Method (AC-X1-01)

### 2.1 Flows

| Flow | Persona (synthetic) | URL | "First useful content" means | Interaction measured |
|---|---|---|---|---|
| Dashboard | Aleppo HR officer (`HR-ALP`) | `/` | team card rendered **and** loading overlay gone | none |
| Employee search | same | `/employees` | first table row rendered **and** overlay gone | type `Haddad` in the table search → the 5 matching rows rendered |
| Contract creation | same | `/contracts/create` | composer rendered, **its city options loaded**, overlay gone | choose an employee (sets the composer's `selected.employee`) → its `draftContractFor` round trip done |
| Leave form | Aleppo staff member (`employee`) | `/leaves` | "Add new" button rendered **and** overlay gone | click "Add new" → leave form modal visible |

All four flows are English/LTR. The overlay is the `#page-gif-overlay` loader, which covers the whole page until a script hides it, so content behind it is not yet useful.

Metrics per page load (all times are the page's own `performance.now()` from navigation start, never the harness's wall clock):

- **Server time, SQL time and query count** come from `Server-Timing` and `X-Perf-*` headers added by the harness's front controller. The front controller wraps the app; it does not change it.
- **Response size:** uncompressed (`X-Perf-Raw-Bytes`) and on the wire (DevTools `encodedDataLength`).
- **Navigation-ready** = `livewire:initialized`, the moment wire actions work.
- **Settled** = the Livewire requests the page fires on its own after load have finished.
- TTFB, FCP, LCP, the load event, CLS and the DOM size are also recorded.

### 2.2 Fixture (synthetic)

`tests/Performance/Baseline/build-fixtures.php` is deterministic. It uses seed `20261016`, and two builds gave identical data (sha256 of the key tables `a347e781…`).

**Volumes** match the dev database's row counts on 2026-10-16. Only counts were read, never records.

| Table | Fixture | Dev DB |
|---|---|---|
| employees (active) | 278 (196) | 278 (196) |
| users | 261 | 261 |
| contracts (active) | 314 (196) | 314 (208) |
| positions | 168 | 168 |
| leave balances | 1,615 | 1,652 |
| leave requests | 63 | 63 |
| notifications | 1,050 | 1,050 |
| avatars (avg size) | 191 PNG (247 KB) | 191 PNG (245 KB) |

**Per-city split**, as `[active, inactive]`, also matches, because city scope decides what an `HR-ALP` officer sees: ALP 60/11, DMS 29/60, HMS 62/2, LAT 30/6, RHO 9/0, TAR 6/3.

**Reference data** comes from the existing organisational seeders: cities, centers, programs, departments, positions, leave rules and types. The people are generated (`synth.NNN`, `@example.test`); none comes from `sampleData.php`.

**Not seeded:** news (0 in the dev DB too), activity log, courses and evaluations.

**Dates follow the build day:** birthdays, leave and contract ends sit around the day the fixture is built.

Full manifest: [evidence/x1/fixture-manifest.json](evidence/x1/fixture-manifest.json).

### 2.3 Runtime

| Item | Value |
|---|---|
| Machine | AMD Ryzen 7 7445HS (6 cores / 12 threads), 15.3 GB RAM, Windows 11 (10.0.26300). Browser and server on the same machine. |
| Browser | Chromium 151.0.7922.34, headless, Playwright 1.62.0 (Python 3.10.6) |
| PHP | 8.5.11 ZTS, OPcache on (`validate_timestamps=0`), `APP_ENV=production`, `APP_DEBUG=false` (Laravel Boost off, mail `array`) |
| Server | 6 × `php -S` + `router.php` behind a threaded proxy on `127.0.0.1:8390`. Six workers match a browser's six HTTP/1.1 connections per host. A single `php -S` would queue the dashboard's 191 avatar requests ahead of the page's scripts. |
| Web-server rules | `router.php` applies `public/.htaccess`: gzip level 6 for HTML/CSS/JS/JSON/SVG, and `Cache-Control: public, max-age=31536000, immutable` for static assets. The brotli branch is not emulated. |
| Database | SQLite file, WAL, `busy_timeout` 10 s (production uses MySQL) |
| Assets | `npm run build` (Vite 8) from the measured sources |

### 2.4 Profiles and network

| Profile | Viewport | Network | CPU |
|---|---|---|---|
| desktop | 1440 × 900, DPR 1 | loopback, unthrottled | 1× |
| phone | 375 × 812, DPR 3, touch, Android Chrome UA | **Slow 4G** via DevTools `Network.emulateNetworkConditions`: latency 562.5 ms, down 180,000 B/s (1.44 Mbit/s), up 84,375 B/s (675 kbit/s). These are DevTools' "Slow 4G" preset values, written out. | 4× slowdown |

### 2.5 Conditions and runs

| Condition | Meaning | Runs per flow × profile |
|---|---|---|
| cold | workers restarted (empty OPcache), compiled views and application cache cleared, new browser context (empty HTTP cache): the first visit after a deploy | 5 |
| first-visit | server warm, new browser context: a user's first visit | 5 |
| warm | server warm, same context after one unrecorded priming visit: a repeat visit | 5 |

That makes 2 profiles × 4 flows × 15 recorded runs = **120 recorded runs**, plus 8 priming visits. All 128 completed (0 failed), on 2026-10-16 between 14:50 and 15:35 (+03:00).

**One request failed:** the loader video was cancelled once (phone, employee search, first-visit #1). No metric depends on it.

## 3. Results — medians (AC-X1-02)

Medians of 5 runs. Every cell's min–max range and the remaining metrics are in [evidence/x1/summary.md](evidence/x1/summary.md), and every raw run is in [evidence/x1/raw-runs.jsonl](evidence/x1/raw-runs.jsonl).

### 3.1 Server side (desktop runs; the phone runs give the same counts)

| Flow | Document server time, warm / cold (ms) | SQL queries | HTML raw / gzip (KB) | Livewire during load: server ms · queries | Interaction: server ms · queries · response raw/gzip (KB) |
|---|---|---|---|---|---|
| Dashboard | 736 / 3,011 | 227 | 1,212 / 54.9 | 72 · 7 (notifications) | — |
| Employee search | 2,213 / 4,896 | 815 | 441 / 36.9 | **2,002 · 708** (`employee-table::init`) | 808 · 256 · 171 / 12.2 |
| Contract creation | 380 / 2,346 | 120 | 208 / 35.7 | 164 · 24 (`locationOptions`) | 88 · 18 · 1.8 / 3.0 |
| Leave form | 316 / 2,380 | 146 | 185 / 28.4 | 128 · 18 (`my-leave-request-table::init`) | 128 · 37 · 37.9 / 7.9 |

**Avatars:** each is its own authorized PHP request (`/employee-photo/{id}`), with a median of about 85 ms of server time and about 8 queries.

### 3.2 What the user waits for (ms)

| Flow | Profile | First useful content: warm · first-visit · cold | Navigation-ready: warm · first-visit · cold | Settled: warm · first-visit | Interaction: warm |
|---|---|---|---|---|---|
| Dashboard | desktop | 2,247 · 2,796 · 6,921 | 3,194 · 4,242 · 7,714 | 3,333 · 4,406 | — |
| Dashboard | phone | 5,777 · 9,307 · 11,633 | **16,772 · 100,878 · 103,285** | 17,840 · 102,062 | — |
| Employee search | desktop | 3,106 · 4,531 · 7,588 | 2,733 · 6,048 · 8,649 | 4,803 · 8,084 | **1,413** (debounce 508) |
| Employee search | phone | 5,304 · 16,179 · 18,868 | 4,726 · 30,837 · 33,776 | 6,978 · 33,045 | 1,781 |
| Contract creation | desktop | 1,158 · 2,607 · 6,954 | 757 · 2,302 · 6,568 | 1,158 · 2,607 | 133 |
| Contract creation | phone | 4,007 · 12,358 · 13,258 | 2,417 · 10,747 · 11,652 | 4,007 · 12,358 | 876 |
| Leave form | desktop | 1,070 · 1,593 · 4,451 | 670 · 1,628 · 6,549 | 1,070 · 1,810 | 175 (to modal visible) |
| Leave form | phone | 2,935 · 5,970 · 7,729 | 2,466 · 10,856 · 11,956 | 3,362 · 11,713 | 829 |

### 3.3 Bytes per load (KB on the wire)

| Flow | First visit (empty cache) | of which avatars | of which third-party CDNs | Warm | Requests |
|---|---|---|---|---|---|
| Dashboard | 15,487 | 14,183 | 477 | 494 | 218 |
| Employee search | 4,792 | 3,649 | 428 | 202 | 38 |
| Contract creation | 1,364 | 233 | 428 | 190 | 23 |
| Leave form | 1,382 | 255 | 428 | 185 | 22 |

## 4. Ranked bottlenecks (measured; owners named, no fix proposed)

They are ranked by how much user time they cost across the measured flows. "Owner" means the code that produces the cost, located by measurement: the SQL origins come from [evidence/x1/query-profile-hr.json](evidence/x1/query-profile-hr.json) and [query-profile-staff.json](evidence/x1/query-profile-staff.json).

**1. The dashboard loads every employee's photo, eagerly, at full size, and most of those requests are refused.**
- **What happens:** the team card renders an `<img>` for all 191 people in the organisation, with no `loading="lazy"`. Each image is a full PHP request serving the original ~245 KB PNG into a 34 px circle.
- **Cost:** 14.2 MB on a first visit. On the phone, navigation-ready arrives at **101 s** on a first visit and 16.8 s on a repeat visit. About 16 s of summed server time per load.
- **Why repeat visits stay slow:** 134 of the 191 requests return **403**, because `EmployeePolicy::viewAvatar` limits an `HR-ALP` officer to Aleppo. A 403 is not cacheable, so every repeat visit pays 134 round trips again.
- **Owners:** `resources/views/components/dashboard-cards/team-members-card.blade.php:74`; `app/Livewire/Dashboard/Index.php` `getTeamMembersProperty()` (org-wide list); `app/Policies/EmployeePolicy.php:25`; `app/Http/Controllers/EmployeeAvatarController.php` (original file, no size variant).

**2. Permission checks are re-queried on every call.**
- **What happens:** `User::hasActivePermission()` runs about 3 queries per call, and `activeRole()` re-loads the role on each call (`app/Models/User.php:102-109`, `:193-204`).
- **Where it multiplies:** `EmployeeTable::link()` calls it once per table **cell** (`app/Livewire/Employee/EmployeeTable.php:88-93`), and the role column calls `myRole()` once per row (`:169`).
- **Share of all queries:** 784 of 812 on `/employees`, about 109 of 220 on the dashboard, 101 of 116 on contract creation and 90 of 137 on `/leaves`.
- **Measured cost:** this is what makes the employees page cost 2.2 s on the server warm, its post-load `init` 2.0 s, and each search 0.8 s.

**3. Every table renders twice.**
- **Cause:** the app's override `resources/views/vendor/livewire-table/livewire/livewire-table.blade.php:2` always adds `wire:init="init"`. The package adds it only when `deferLoading` is on.
- **What happens:** right after the page arrives already rendered, a second request re-renders the whole table.
- **Cost on `/employees`:** 708 queries and ~2.0 s of server time. *Settled* lands 2.1 s after navigation-ready (desktop warm), and a search typed in that window queues behind it.
- **Elsewhere:** on `/leaves` the same pattern costs 18 queries and ~128 ms. All 21 `LivewireTable` pages use this view; only these two were measured.

**4. The loading overlay hides content that has already rendered.**
- **What happens:** `#page-gif-overlay` is removed by a script at the end of `<body>`, after a further 300 ms plus a 250 ms fade (`resources/views/components/layouts/app.blade.php:38-44`, `:80-106`).
- **Desktop, warm:** content is visible behind it 0.55–1.25 s before it lifts.
- **Phone, warm:** 1.8–4.3 s before it lifts. On the phone the script also runs only once the parser reaches the end of the body, after the blocking `livewire.min.js`. That cause is likely, and A7 should confirm it.
- **Effect:** in every warm run, *first useful content* is determined by the overlay, not by the page.

**5. The dashboard document itself is heavy.**
- **Size:** 1.2 MB of HTML, 5,576 DOM elements and 227 queries. The team card writes every employee into the page with its own Alpine `x-show` filter.
- **Server time:** 736 ms warm.
- **Layout shift:** CLS 0.23 on the phone's first visit (0.11 on desktop).

**6. Every first visit carries ~430 KB of third-party assets, plus the loader video.**
- **Assets:** Font Awesome (CSS, JS, webfont) from cdnjs and Flowbite from jsdelivr (`resources/views/partials/scripts.blade.php:3,5` and the head partial), plus the ~150 KB loader video.
- **Effect on the phone:** on Slow 4G even the simplest pages (leave form, contract creation) reach navigation-ready at about 11 s on a first visit.

**7. Cold start after a deploy or view clear.**
- **Cost:** about 2.0–2.3 s of extra server time on the first request to each page, which is Blade compilation and an empty OPcache.
- **Classification:** an operations matter (whether views are pre-compiled at deploy). It is recorded here, not attributed to UX code.

**8. Search debounce.** The table search waits 500 ms before sending (`resources/views/vendor/livewire-table/bar/search.blade.php:5`). That is about a third of the 1.4 s from typing to results on desktop.

**Healthy:** opening the leave form (175 ms on desktop) and choosing an employee in the composer (133 ms) are fast. Their ~850 ms on the phone is about one Slow 4G round trip (562.5 ms latency plus upload), not server work.

## 5. Findings outside performance (escalate; not changed by X1)

| Finding | Evidence | Destination |
|---|---|---|
| A city-scoped HR officer's dashboard shows **134 broken team photos**. The team card lists the whole organisation, but `viewAvatar` refuses other cities. Either the card or the policy is wrong; which one is the directory-scope decision. | dashboard runs: `avatar` statuses `{200: 57, 403: 134}` in every run | **A2-D / DEC-01**, then A2 |
| `hasActivePermission()` has no per-request memo. Besides the performance cost (§4.2), every authorization decision on a page re-reads the role pivot. That is correct, but it is worth noting for A2 when it adds checks. | query profiles | A2 (awareness), X1-I |

## 6. Proposed targets (PROPOSED, not measured, for X1-I to adopt or reject)

These are deliberately kept out of §3–§4. Each is relative to this baseline, re-measured with the same harness, same fixture and same profiles:

- **Dashboard, phone:** navigation-ready on a first visit under 15 s (now 101 s), and no 403 responses.
- **`/employees`:** at most 100 queries for the document and none for a duplicate post-load render. Search warm on desktop under 800 ms, debounce included (now 1.4 s).
- **Warm desktop, every flow:** first useful content within 300 ms of the content being rendered (now 0.55–1.25 s, see §4.4).

## 7. Reproduction recipe

Run from the repository root, with PHP 8.5 and Python 3 with Playwright (`pip install playwright`, `python -m playwright install chromium`). `PERF_HOME` must be a directory **outside** the repository. Everything the harness writes goes there.

```bash
php artisan config:clear          # the harness refuses to run on a cached config
npm run build                     # measure the built assets, not the Vite dev server
export PERF_HOME=/path/outside/repo/hc-hr-perf

# 1. synthetic database (about 20 s)
php tests/Performance/Baseline/build-fixtures.php

# 2. measurements (about 45 min; the phone dashboard dominates)
python -I tests/Performance/Baseline/measure.py --perf-home "$PERF_HOME" --out "$PERF_HOME/run" \
    --cold 5 --first-visit 5 --warm 5

# 3. medians
python -I tests/Performance/Baseline/summarize.py "$PERF_HOME/run"

# 4. where the SQL comes from (Git Bash: set MSYS_NO_PATHCONV=1 so "/" is not rewritten)
php tests/Performance/Baseline/query-profile.php hr.alp@example.test / /employees /contracts/create
php tests/Performance/Baseline/query-profile.php staff.alp@example.test /leaves
```

**Before–after comparisons (X1-I)** use the same commands on the changed branch, after rebuilding the fixture so its dates line up. Any change to the fixture, the profiles or the machine must be disclosed (AC-X1-I-01).

**Safety:**
- `env.php` forces the SQLite file, its own storage folder, `MAIL_MAILER=array` and production mode before Laravel boots.
- It aborts if `bootstrap/cache/config.php` exists or if the booted database is anything else.
- No request in the harness writes HR data. The interactions read, open a form, or ask for a draft. A contract save is **not** measured.

## 8. Limitations

- **Not production hardware or software.** SQLite instead of MySQL, `php -S` workers instead of Apache, HTTP/1.1 only (HTTP/2 would change how the avatar burst queues), gzip but not brotli, and browser and server on one laptop. The absolute server times are therefore not production times. **Compare numbers only within this harness.**
- **Desktop network is loopback.** The only network delay measured is the phone's emulated Slow 4G.
- **Third-party CDN timings** depend on this machine's internet connection during the run.
- **English/LTR only.** Arabic/RTL was not measured. Font and translation costs may differ, which is input for A4.
- **One role per flow.** An `HR-SYR` (national) officer would see 278 employees and no 403s; that was not measured.
- **Contract save is not measured,** because it writes. The composer interaction sets the employee through the composer's state rather than by typing in the combobox.
- **Synthetic people and dates relative to the build day.** Birthdays and upcoming-leave cards therefore vary with the day the fixture is built. Counts and per-city volumes are fixed.
- **Prerequisites are drafts.** P0 and A1 are still awaiting review. No staging host exists. Real-user (field) data was not collected.

## 9. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| AC-X1-01: report includes fixture scale, viewport, browser/runtime, network settings, run count and timestamp | **Met** | §2.2–§2.5, [evidence/x1/environment.json](evidence/x1/environment.json) |
| AC-X1-02: raw measurements and medians for the four flows; no invented baseline | **Met** | 128 raw runs in [raw-runs.jsonl](evidence/x1/raw-runs.jsonl); medians with min–max in [summary.md](evidence/x1/summary.md) / [summary.json](evidence/x1/summary.json); §3 copies only those numbers |
| AC-X1-03: no UX/performance code changed during capture | **Met** | The X1 commits touch only `tests/Performance/Baseline/` and `docs/ux/`. `git diff --stat 5726436f..HEAD -- app resources routes config database bootstrap public` is empty. `public/build` was rebuilt from unchanged sources (gitignored). |

## 10. Handoff

| Consumer | Input from X1 |
|---|---|
| **A7** (waiting and the blocking loader) | §4.4: the overlay costs 0.55–1.25 s on desktop and 1.8–4.3 s on the phone after content is already rendered. Re-run the same flows to show before and after. |
| **A5** (dialogs and controls) | Leave-form open time (175 ms on desktop, 829 ms on the phone) and the composer interaction are the baselines for any modal change. |
| **A4** (EN/AR) | Arabic was not measured. If A4 changes fonts or text weight, add an Arabic run first: the same flows under the `/ar/` URL prefix (`FLOWS` paths in `measure.py`). |
| **X1-I** (optimise measured bottlenecks) | §4 ranking, owners and the harness. Re-measure against `evidence/x1/` with the same fixture, profiles and conditions. |
| **A2-D / A2** | §5: dashboard team photos refused for city-scoped HR roles (DEC-01). |

Review sign-off: _pending (QA engineer, technical lead)_.
