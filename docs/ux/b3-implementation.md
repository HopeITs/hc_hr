# B3: Loading, empty and retry states

Task B3 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-05). Prerequisites: B1, B0.
Status: **implemented, automated tests green, not reviewed, browser checks pending.**
Detail per area: [dashboard](b3-dashboard-notes.md), [employee/contract](b3-employee-contract-notes.md),
[leave/checklist/notifications](b3-leave-checklist-notifications-notes.md).

## Shared pieces
- `x-center-empty-state`: `variant` empty | filtered | error, `action` slot, `role=status` (alert for error). Old callers unchanged.
- `x-ui.skeleton` (`motion-safe:animate-pulse`, aria-hidden) and `x-ui.load-error` (Retry, `role=alert`, stable min height).
- `HandlesLoadFailure` (`guardedLoad`, `retryLoad`): reports the exception, never shows its text.
- `HasListStates` / `HasScreenListStates` for tables; hook added in the app-published livewire-table views (only tables using the trait change).
- Copy: `general.state-*`, `states.php`, EN and AR.

## Findings fixed on the way
- `AllLeaveRequestModule::create()` had no server check; now needs `leaves.create` and an HR/super-admin active role.
- "Request leave" button showed for every role; now needs `leaves.create`.
- Notification counter polled twice; dropdown polling replaced by events.
- Dashboard built an unused `todayEvents` (36 N+1 queries); removed from render. Page 58 queries / ~207 ms before (100 employees).
- Soon-ending card N+1 avatar queries; notes ran 3 identical queries.

## Lazy loading decision
Lazy: soon-ending contracts, no-leaves employees (heaviest independent cards). Not lazy: cached statistics cards, notes, calendar, my-leaves.
Upcoming leaves stays eager: its existing test needs `Livewire::withoutLazyLoading()` first. Measurements in the dashboard notes.

## Acceptance evidence
| AC | Evidence |
|---|---|
| AC-B3-01 | `ListStatesTest`, `LeaveChecklistNotificationStatesTest`, `DashboardCardStatesTest`, `HandlesLoadFailureTest`: empty vs filtered-empty vs forced load failure per screen |
| AC-B3-02 | Clear filters restores rows (search, column search, status tab); create action present/absent per permission, plus server refusal |
| AC-B3-03 | Lazy placeholders; no `wire:poll`/listener duplicates found on dashboard; repeated retry causes no writes; a failing source only replaces its own cards |

Suite on PHP 8.5, SQLite memory: **827 passed, 4612 assertions, 0 failed**. `npm run build` OK. Pint run.
`LivewireMutationCoverageTest` needed the new public methods classified in `config/access-control.php` (view state/read-only, commented).

## Browser checks (22 Oct 2026, synthetic SQLite copy of the B6 fixture, HR persona, built-in browser)
| Check | Result |
|---|---|
| Employees, EN 1440x900: no-match search | "No results match your filters" + Clear filters; click restored the URL, 15 rows, "73 results" live text |
| Employees, AR/RTL 375x812: no-match search | Arabic filtered state with Clear filters, `dir=rtl`, no horizontal page overflow (scrollWidth 375) |
| Contracts, AR 1440x900: no-match search | Arabic filtered state, 4 live regions |
| Dashboard EN 1440x900 / AR 375x812 | Cards load (lazy ones included), no error or stuck `aria-busy`, no overflow |
| Notifications EN | Live region "N unread notifications" present |
Console: CSP "inline script blocked" errors (hash `sha256-T6yc...`) also appear on `/ar/news`, a page B3 did not touch and whose scripts B3 did not edit, so they pre-date B3. Not investigated.

## Second browser pass (same day, same synthetic copy)
Forced failures by renaming tables in the throwaway SQLite copy, then restoring them.
| Check | Result |
|---|---|
| My leaves, empty dataset (non-HR persona, 0 requests) | "No leave requests yet" + Request leave (44 px, `role=status`); 1440, 812x375 and 720x450 (200% equivalent) with no page overflow |
| My leaves, `leave_requests` missing | **Found: HTTP 500** (tab counts in `mount()` unguarded). Fixed in both leave modules; now shows the load-error panel with Retry. After the table came back, Retry recovered the page |
| Notifications, `notifications` missing | Page and dropdown show the load-error panel, no 500, no SQL text |
| Checklist, `checklists` missing | **Found: HTTP 500 on every page** (the layout `Sidebar` counted checklists unguarded). Fixed; the page shows the load-error panel |
| Checklist AR 375x812 | RTL, no overflow. **Found:** count read "3 العنصرs" (English plural suffix on an Arabic word). Now uses `states.items-count`: "3 عناصر" |
| All leaves (HR), filtered-empty at 812x375 | Clear filters by keyboard (Enter) restored 15 rows. **Found:** focus fell to `<body>`. Fixed: focus moves to the search box (`list-filters-cleared`) |
| Non-HR persona | Lara (employee/manager role) used for the leaves, checklist, notifications and dashboard checks; HR persona for all leaves |
Regression tests added: `my_leaves_page_survives_a_failed_count_query`, `list-filters-cleared` dispatch. Suite: **828 passed, 4616 assertions**; build OK.

## Limits (still not done)
- **No screen reader** (none in this environment). Live regions and roles were checked from the DOM only. A real NVDA/VoiceOver pass is pending.
- Real 200% browser zoom was approximated by a 720x450 viewport. Visible focus ring was not confirmed (a script-focused button reported `outline: none`, which may only reflect `:focus-visible` rules).
- Slow loading was not simulated in the browser (no network throttling); lazy dashboard placeholders are covered by tests only.
- A role without `leaves.create` was not logged in; that case is covered by tests.
- Retry success still removes the retry button without moving focus.
- Tables have no skeleton rows: dim plus live-region text; no `aria-busy` there.
- `x-dashboard-cards.*` empty states, `news-card` query and the team card (heaviest block) untouched.
- Dept/job-level charts read data once; a successful retry reloads the page.
- Pint changed line endings in some files; diffs may look larger.
- Nothing committed.
