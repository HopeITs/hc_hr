# X1-I · Optimize measured bottlenecks and safe network recovery: implementation notes

Status: **implemented and measured, awaiting review** (QA engineer + technical lead). Not marked done until reviewed.
Date: 2026-10-28. Task: X1-I in [ux-prompts-v3.md](ux-prompts-v3.md) (R-23). Consumers: X2-R, G-R.

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision measured "before" | `34737b33` (Merge branch 'G-C'): X1, G-B and G-C are in |
| Revision measured "after" | `e6b2f46c` (`1b5f4165` + `e6b2f46c`, the two X1-I commits) — see §4.1 for why it is measured on its own |
| X1 | [performance-baseline.md](performance-baseline.md): ranked bottlenecks (§4), harness and fixture (§2, §7) |
| G-B / G-C | [g-b-gate.md](g-b-gate.md), [g-c-gate.md](g-c-gate.md): the leave decision's `lockForUpdate` + `assertStillPending` (G-B-1) and the submission tokens of leave submit and contract create (B2) were already in place; X1-I builds on them |

**Where the X1 ranking stood on 2026-10-28.** Several X1 bottlenecks had already moved since 2026-10-16, through other tasks:

| X1 §4 | Status before X1-I |
|---|---|
| 1. Dashboard photos, 134 × 403 | The 403s were already gone: `viewAvatar` follows the Directory tier (DEC-01, [access-policy.md](access-policy.md) §8), so a regional HR officer may see every active colleague's photo. The 191 eager full-size photos remained. |
| 2. Permission re-queried per call | Remained (plus a city-scope lookup per call, see §2) |
| 3. Every table renders twice | Remained |
| 4. Loading overlay | **Removed by A7** ([a7-implementation.md](a7-implementation.md)) |
| 5. Heavy dashboard document | Remained (5,789 DOM nodes) |
| 6. Third-party assets | Remained; not in X1-I's scope (no measured owner change) |
| 7. Cold start | Operations matter, not measured here |
| 8. Search debounce | Kept at 500 ms: no evidence that a shorter value would be safe for the server |

## 2. What changed (performance)

| File | Change | Bottleneck |
|---|---|---|
| `app/Models/User.php` | Per-instance memo for `activeRole()`, `myRole()`, `hasActivePermission()` and `activeCityIds()`. Dropped whenever the user's role or permission pivot is written (`newMorphToMany` → `newPivotQuery`/`newPivotStatement`), when the `roles`/`permissions` relations are set, unset or reloaded, on `refresh()`, and when Spatie's permission cache is rebuilt. City scope is keyed by role name. | X1 §4.2 |
| `resources/views/vendor/livewire-table/livewire/livewire-table.blade.php` | `wire:init="init"` only when `deferLoading` is on (as the package does). No app table sets `deferLoading`; `init()` only flipped `initialized`. | X1 §4.3 |
| `app/Livewire/Dashboard/Index.php` | Each team member gets `canViewAvatar` from the same `viewAvatar` gate the photo route enforces; deputies eager-load `employee.contracts.position`. | X1 §4.1 |
| `resources/views/components/dashboard-cards/team-members-card.blade.php` | `<img loading="lazy" decoding="async" width="34" height="34">` for permitted people; initials (`aria-hidden`) plus an `sr-only` name otherwise, with **no photo URL in the HTML**. | X1 §4.1, task step 2 |

**Not changed, on purpose:**
- **No smaller photo variant.** It needs either re-encoding in PHP per request or a cached derivative with its own authorization path; CLAUDE.md forbids re-encoding stored originals in place. Lazy loading already removes most of the cost (§4). Recorded as a follow-up.
- **`EmployeePolicy` / who may see a photo.** A photo is shown wherever the name is (product owner, 2026-10-28, and access-policy §8). The card gate only matters for a pinned former employee the Directory hides.

## 3. What changed (network recovery, task steps 3 and 7)

**Inventory of user-facing writes** (AC-X1-I-02):

| Write | Operation key | Race-safe guard | X1-I |
|---|---|---|---|
| Leave request submit | `form.submission_token` (B2), cache per user | Cache lock on the key; overlap check under employee and balance row locks | Lookup added |
| Leave decision (approve / reject) | The request itself | `lockForUpdate` + `assertStillPending` (G-B-1); balance moved only inside the lock | Lookup added |
| Contract create (composer) | `submissionToken` (B2, `#[Locked]`) | Cache lock; the stored id is only returned if the user may view it | Lookup added |
| **Contract renew-as-is (dialog)** | **None** | **None; contract and balances were two writes** | **Gap fixed:** `#[Locked]` token per opened dialog, cache lock, replay redirects to the successor, contract + balances in one `DB::transaction` |
| Employee create | `employee-create-submission` token | Cache lock | Unchanged (no lookup) |
| Leave update | — | State-based: delta = new − stored snapshot, a repeat is delta 0 | Unchanged |
| Leave delete | — | The row is gone on repeat | Unchanged |

**"Result unknown" instead of "Try again"** (task step 3, data-integrity §7):
- `app/Support/UnknownOutcome.php` lists the writes whose answer may be lost after they were applied (component → actions). The layout passes it to the browser through `confirm-dialog.blade.php`.
- `resources/js/feedback.js`: when one of those requests fails at network level, B0's generic **Try again** is **not** offered (it would re-run the write). An info toast says the result could not be confirmed, the entries stay, and **Check result** calls the component's `reconcileOperation()`.
- `reconcileOperation()` (ContractCreate, ContractRenewModal, MyLeaveRequestModule, AllLeaveRequestModule) only **reads**: the stored operation key, or the request's current state for a decision. Applied → success toast (and the redirect for contracts); not applied → "nothing was saved, your entries are still here".
- Authorization: every `reconcileOperation` is mapped in `config/access-control.php` (`leaves.create`, `leaves.decide`, `contracts.create`), so the Livewire middleware refuses a lost permission, and each method re-checks in-method (active permission, `view` gate, renew gate). Another user's token finds nothing.
- Strings `general.unknown-result.*` in EN and AR. The toast is announced through B0's `role="status"` / `aria-live="polite"` region.
- **Bug found in the browser check and fixed:** `checkResult()` called `Livewire.find(id).$wire.reconcileOperation()`. In Livewire 4 `find()` already returns `$wire`, so the button sent nothing. Now `Livewire.find(id)?.$call('reconcileOperation')`, the pattern `callBack()` uses. No JS test runner exists, so the browser run (§5.3) is the evidence.

## 4. Measurement (AC-X1-I-01)

### 4.1 Method and every change from X1's method

Same harness (`tests/Performance/Baseline/`), same fixture builder and seed `20261016`, same profiles (desktop loopback; phone 375×812, Slow 4G, 4× CPU), same machine, browser and PHP as X1 §2. Both sides measured the same day, back to back, each with a freshly built fixture and its own `npm run build`. Comparison: `python -I tests/Performance/Baseline/compare.py before/raw-runs.jsonl after/raw-runs.jsonl` (new, X1-I).

**Disclosed differences from X1:**

1. **Run count: 3 first-visit + 3 warm per cell, no cold runs** (X1: 5 + 5 + 5). Shortened on the developer's instruction; cold start is an operations matter (X1 §4.7). Medians of 3 are noisy: treat differences under ~20 % on a single cell as noise unless the server-side counts agree.
2. **Baseline re-measured on 2026-10-28, not taken from X1's 2026-10-16 numbers.** A7, G-B and G-C changed the measured pages since; comparing with X1's numbers would credit X1-I with their effects.
3. **Harness fix — overlay.** A7 removed `#page-gif-overlay`, which X1's "first useful content" waited for. With no overlay in the page, first useful content = content visible. Pages that still have an overlay are measured as before.
4. **Harness fix — phone table.** Below `md` the table renders rows as cards and hides the `<tr>`. Content and search checks accept a visible `tr[data-tour="table.row"]` **or** card `li[wire:key^="card-"]`. X1 measured the phone search against `<tr>` rows, which were visible then.
5. **"After" is the X1-I commit alone (`e6b2f46c`).** Another task's commits (T-1 holidays, `a9e767fe`, `b4866561`) and uncommitted holiday work landed in the shared checkout during the session. A run of that mixed tree is kept as a cross-check only (`evidence/x1i/after-mixed/`), not used for the comparison.
6. Each side runs in its own worktree **with its own `vendor/` copy**. A first attempt shared `vendor/` through a junction; Composer resolved the real path and the "before" server loaded `app/` from the working tree. That run was discarded.

### 4.2 Results

Medians of 3 runs; 56 of 56 runs ok on each side. Every cell and metric: [evidence/x1i/comparison.md](evidence/x1i/comparison.md) ([json](evidence/x1i/comparison.json)); raw runs in `evidence/x1i/{before,after}/raw-runs.jsonl`.

**Server side** (identical on both profiles):

| Page | Document queries | Queries in the post-load re-render | Document server time, desktop warm |
|---|---|---|---|
| Dashboard `/` | 872 → **398** | 0 → 0 | 1,281 → 970 ms |
| Employees `/employees` | 923 → **311** | 87 → **0** | 1,454 → 583 ms |
| Contract composer `/contracts/create` | 859 → **296** | 22 → 21 (its city options, not a re-render) | 1,207 → 481 ms |
| Leaves `/leaves` (staff) | 445 → **142** | 15 → **0** | 580 → 297 ms |

**What the user waits for** (ms):

| Flow · profile · condition | First useful content | Navigation-ready | Settled |
|---|---|---|---|
| Dashboard · phone · first visit | 4,993 → 5,026 | **274,473 → 18,618** | 274,473 → 50,983 |
| Dashboard · phone · warm | 5,986 → 2,518 | **27,683 → 8,856** | 261,266 → 8,856 |
| Dashboard · desktop · first visit | 1,802 → 1,630 | 4,615 → 2,557 | 4,615 → 2,557 |
| Employees · desktop · warm | 1,759 → **836** | 1,936 → 1,031 | 2,239 → 1,031 |
| Employees · phone · first visit | 7,848 → **3,991** | 18,899 → 17,692 | 19,950 → 17,692 |
| Contract composer · phone · warm | 7,164 → 5,317 | 4,870 → 3,427 | 7,164 → 5,317 |
| Leaves · desktop · warm | 878 → 528 | 1,076 → 692 | 1,224 → 692 |
| Leaves · desktop · first visit | 910 → 943 | 1,078 → 1,364 | 1,227 → 1,364 |

**Bytes and photos (dashboard):** first visit 48,320 → 13,246 KB on desktop (191 → 49 photo requests) and 48,320 → 9,274 KB on the phone (191 → 33); warm phone 45,658 → **68 KB**. No 403 on either side (see §1).

**Reading the numbers:**
- The phone dashboard's 274 s "before" is the eager photo burst over Slow 4G (~48 MB at 180 KB/s). X1 measured 101 s on 2026-10-16 when 134 photos were refused; once DEC-01 allowed them, all 191 downloaded in full.
- Leaves desktop first visit is the one cell that reads slower. Per-run ranges overlap (before 856–1,504, after 996–1,403 ms first useful) and its server time and queries fell, so it is noise at n = 3, not a regression.
- The interactions (pick an employee, open the leave form) are unchanged within noise, with fewer queries (18 → 12, 34 → 29). Search: see §4.3.
- The phone dashboard first visit's CLS rise (0.02 → 0.25) is the product tour, not X1-I: §5.4.

**Against X1's proposed targets (§6 of the baseline):**

| Target | Result |
|---|---|
| Dashboard phone first visit navigation-ready < 15 s, no 403 | **Not met:** 18.6 s (was 274 s); no 403 ✓. The rest is the 1.4 MB document, scripts and third-party assets on Slow 4G (X1 §4.5–4.6) |
| `/employees` ≤ 100 document queries, none for a duplicate render | Duplicate render: **met** (87 → 0). Document: **not met**, 311 (was 923); the rest is listed in §7 |
| Search warm desktop < 800 ms | **Not measurable** with the harness as it is (§4.3) |
| First useful content within 300 ms of rendering | Met by A7 (no overlay), not by X1-I |

**Cross-check:** the run of the mixed tree (X1-I + T-1 holidays, `evidence/x1i/after-mixed/`) gives the same picture within noise (e.g. `/employees` 313 queries, phone dashboard first-visit navigation-ready 18.7 s).

### 4.3 Not measured

- **Table search interaction.** The search term persists per user across visits and browser contexts (an earlier phase's search persistence), so the harness's search already shows the 5 results on load and measures ~1–7 ms with **no request**, before and after alike. The interaction numbers for `employee_search` are therefore meaningless in both columns. Fixing the harness (clear the persisted search before the interaction) is a follow-up.
- Arabic/RTL performance (as X1). Cold start. Contract save (writes).

## 5. Verification

### 5.1 Tests

| Suite | Result |
|---|---|
| Full suite (`php artisan test`), final tree (2026-10-28 15:47, incl. later C5/T-1 commits) | **1147 passed, 1 failed** (503 s). The failure is `LeaveRequestWorkflowTest::gc1…`: `UNIQUE constraint failed: cities.slug` on a randomly generated slug (`A69`) in that G-C test's fixture. It passed 5 of 5 runs in isolation and was seen once before by the recovery work; a flaky fixture, not X1-I. Follow-up: make the fixture's slug deterministic. |
| Full suite, before the city-scope memo | 1110 passed, 0 failed (524 s) |
| `tests/Feature/Performance/PermissionMemoTest.php` (8) | Query ceilings; role switch serves fresh permissions **and cities**; deactivating every role revokes at once; role and direct grants/revokes seen immediately; salary-blind rules unchanged (HR yes, super-admin and staff no); HR-ALP sees Aleppo rows, not Homs; non-deferred tables have no `wire:init` |
| `tests/Feature/Performance/DashboardTeamCardTest.php` (3) | Permitted people get a lazy `<img>` with size and alt; a forbidden person gets initials, an `sr-only` name and **no photo URL in the HTML**; query ceiling |
| `OperationRecoveryTest` (4) + additions to `LeaveRequestSubmitFlowTest`, `LeaveRequestWorkflowTest`, `ContractRenewalTest`, `UnsavedWorkProtectionTest` (16) | Replay of leave submit; renew sent twice → one successor, one balance creation; decision sent twice moves the balance once; renew while the lock is held writes nothing; renew token cannot be forged; `reconcileOperation` 403s without `leaves.create` / `contracts.create` / access to the request; another user's token finds nothing; lost answer → found, no second write; never-arrived → nothing written, entries kept; registry maps to real components and methods; EN/AR strings exist, differ and AR is Arabic script |

### 5.2 Concurrency and retry (AC-X1-I-02)

Covered by the tests above at the domain level. The browser run (§5.3) adds the network level: a submit the server **did** process, whose answer was then dropped, created **exactly one** leave request before and after **Check result**; a submit dropped **before** the server created none.

### 5.3 Browser check (AC-X1-I-03), EN/AR × desktop/phone

Synthetic fixture only (X1 harness server); staff persona on `/en/leaves` and `/ar/leaves`, desktop 1440×900 and phone 375×812. Playwright `page.route` on the Livewire update: case 1 = `route.fetch()` then abort (applied, answer lost); case 2 = abort before the server.

| Case | After the drop | Check result | Leave requests |
|---|---|---|---|
| 1 (applied, answer lost) | Info toast "could not confirm…", **no Try again**, dialog and entries kept | One `reconcileOperation` call → success toast, dialog closes | +1 at the drop, still +1 after |
| 2 (never arrived) | Same toast | "Not saved, your entries are still here"; dialog stays with entries | +0 |

All 8 combinations pass. The toast sits in the `role="status"` / `aria-live="polite"` region; on `/ar` the page is `dir="rtl" lang="ar"` and the toast and button are Arabic. Screenshots: [EN desktop, unknown](evidence/x1i/browser/en-desktop-case1-1-unknown.png) · [checked](evidence/x1i/browser/en-desktop-case1-2-checked.png) · [AR phone, unknown](evidence/x1i/browser/ar-phone-case1-1-unknown.png) · [checked](evidence/x1i/browser/ar-phone-case1-2-checked.png) · [EN/AR case 2](evidence/x1i/browser/en-desktop-case2-2-checked.png). Raw: [unknown-results.json](evidence/x1i/browser/unknown-results.json).

Not browser-tested: the leave decision, contract create and renew paths (same `checkResult()` code; server side covered by tests).

### 5.4 Phone dashboard CLS (investigated, not caused by X1-I)

The phone dashboard's first-visit CLS reads ~0.25 after and ~0.02–0.28 before. Layout-shift sources ([cls-instr.json](evidence/x1i/browser/cls-instr.json)): only the **product-tour card** (`resources/views/components/tour/overlay.blade.php`). It is inserted at `top:0` and moved by `placeCard()` ~110 ms later; its base `duration-300` with the default `transition-property: all` animates that move, and every frame counts as a shift. With the tour marked seen, CLS is 0.0001 / 0.0000 ([cls-notour.json](evidence/x1i/browser/cls-notour.json)). Before X1-I the page became ready at ~276 s, at the moment CLS was collected, so the tour often opened after collection: a measurement race, not an improvement. The team-card change produced no shift entries. **Follow-up** for the tour's owner: no transition until the card is placed.

## 6. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| AC-X1-I-01: comparable fixture/network/browser conditions, changes disclosed | **Met** | §4.1 (six disclosed differences), `evidence/x1i/{before,after}/environment.json`, `fixture-manifest.json` |
| AC-X1-I-02: no duplicate records, approvals or balance effects after retries | **Met, with the limits in §7** | §3 inventory, §5.1 tests, §5.2–5.3 browser |
| AC-X1-I-03: EN/AR correctness, permissions and accessible loading feedback preserved | **Met** | §5.1 (permission and salary tests, full suite), §5.3 (EN/AR, RTL, live region), team card keeps names for screen readers |

## 7. Limits and follow-ups

- **Operation keys live in the cache for one day.** If the cache is cleared, a later retry of an already-used contract-create or renew token could write again (leave submit still has its DB overlap check). A unique operation-key column would close this; it needs a migration and was not added.
- **Contract create** stores the contract and its balances outside one transaction (renew now uses one). A crash between the contract insert and the cache write would make Check result answer "not saved" although the contract exists.
- Two renew dialogs in two tabs carry different tokens; only term validation stops a second renewal.
- The memo is per `User` instance: if another instance changes this user's role inside the same request, `auth()->user()` keeps the old answer until the request ends. Role switches redirect, and the app does not run on Octane, so no current path is affected. The Spatie cache-rebuild check reads a protected property of `PermissionRegistrar`; recheck it on a package upgrade.
- Remaining query hotspots on every HR page (after profile): `LeaveRequest.php:208`, `NotificationRequestState.php:47`, `LeaveDecisionAuthority.php:93/64`, `LeaveRequestPolicy.php:91`, and `notifications.counter::updateCount` polling. Ranked candidates for the next round.
- Photos are still served full size when they are loaded.
- Commit `1b5f4165` also carries T-1 files (`BirthdayHolidayCard.php`, `holidays.php`); history was not rewritten.

## 8. Handoff

| Consumer | Input |
|---|---|
| **X2-R** | The "result unknown" toast and Check result are new user-facing behaviour on leave submit/decide and contract create/renew; Try again no longer appears for those writes |
| **G-R** | §4.2 numbers, §6 AC table, §7 limits |
| Tour owner (A-track) | §5.4 tour-card CLS |

Review sign-off: _pending (QA engineer, technical lead)_.
