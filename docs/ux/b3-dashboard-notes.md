# B3 - Dashboard loading / empty / retry states

Scope: `app/Livewire/Dashboard/*`, `resources/views/livewire/dashboard/*` (+ new test `tests/Feature/Dashboard/DashboardCardStatesTest.php`).
Foundation used: `x-center-empty-state`, `x-ui.skeleton`, `x-ui.load-error`, `HandlesLoadFailure`.

## Measurement (SQLite :memory:, 100 employees each with one contract, cold cache, Livewire::test)

Method: temporary test with `DB::listen` around each component mount/render (deleted afterwards). Numbers are indicative only
(SQLite, test boot included in the first call); the query counts are what matter.

| Card | Queries | Time | Notes |
|---|---|---|---|
| Dashboard\Index (whole page, before) | 58 | ~207 ms | 36 queries came from `todayEvents`, which was computed and never rendered (N+1 per birthday) |
| Index::todayEvents alone | 36 | 17 ms | removed from render |
| Index::upcomingEvents / delivered / badges / pending | 2 / 3 / 4 / 5 | ~1 ms | cheap |
| SoonEndingContractsCard | 50 | 23 ms | 5 rows; avatar/name/city/department accessors lazily resolved per row |
| NoLeavesEmployeesStatistics | 10 (grows with headcount) | 13 ms | unbounded `whereHas` + per-row `latestActiveContract()` / `department` in the view |
| UpcomingLeavesCard | 21 (empty set) | 14 ms | grows with visible leaves |
| ContractTypeEmployeesStatistics | 18 cold, 0 warm | 11 ms | `StatisticsCache` (10 min) |
| SalaryPerEmployeeStatistics | 8 cold, 0 warm | 12 ms | cached |
| JobLevelEmployeeStatistics | 7 cold, 0 warm | 11 ms | cached |
| DepartmentEmployeeStatistics | 6 cold, 0 warm | 11 ms | cached |
| EmployeeNotesCard | 4 | 9 ms | `$this->notes` read 3x per render with no memo |
| CalendarCard | 0 | 12 ms | config only |

## Lazy decisions

| Card | `#[Lazy]` | Why |
|---|---|---|
| SoonEndingContractsCard | YES | 50 queries, independent, list only (no JS dependency). Eager-loaded `employee.media` (avatar) to cut the N+1. |
| NoLeavesEmployeesStatistics | YES | cost scales with headcount, independent list card. |
| UpcomingLeavesCard | NO | 21 queries / 14 ms is moderate, and under `Livewire::test()` a lazy component renders only its placeholder, which breaks the existing `UpcomingLeavesCardTest` (outside this task's scope). Revisit together with that test (add `Livewire::withoutLazyLoading()` there, then add `#[Lazy]` + placeholder - already supported by `card-placeholder`). |
| Dept / JobLevel / SalaryPer / ContractType statistics | NO | cached aggregates (0 queries warm, 6-18 cold, ~11 ms); lazy would add one extra round trip per card for no gain, and their charts initialise from inline scripts / `lazy-charts.js`. |
| CalendarCard, EmployeeNotesCard | NO | 0 and 4 cheap queries; above the fold. |
| MyLeavesCard | NO | derived from the same balance Index already checks; shown only when the viewer has a balance. |

Placeholder: `resources/views/livewire/dashboard/card-placeholder.blade.php` (title shown immediately, `min-h-64`, `aria-busy="true"`, sr-only "Loading...", decorative `x-ui.skeleton`).

## AC-B3-03: a slow/broken card must not block the dashboard

- Lazy cards above load in their own requests after the page paints.
- `Dashboard\Index` now loads every dataset through a per-request `section()` wrapper (`guardedLoad` + memo). A failing source no longer 500s the page: only the cards that use it are replaced by `x-ui.load-error` (Retry re-renders and re-runs the loads). Checklist outage -> exactly two cards (delivered items, pending requests), asserted in the test.
- Removed the unused `todayEvents` computation from `Index::render()` (-36 queries at 100 employees). The getter `getTodayEventsProperty()` is left in place (dead code, harmless; delete in a cleanup).
- `teamMembers` is memoized per request: the team card reads `$this->teamMembers` after `render()` already built it (previously evaluated twice).
- Polls / listeners / duplicate writes: no `wire:poll`, `#[On]` or `$listeners` exist in any dashboard component or view. The notes card closes its modal on the same tick it calls Livewire, so a double click cannot submit twice. Nothing to fix. EmployeeNotesCard read `$this->notes` three times per render (3 identical queries); now memoized.

## Per-card state table

| Card | Loading | Empty (dataset) | Error + retry | Empty action (permission-aware) | Layout |
|---|---|---|---|---|---|
| Soon ending contracts | lazy placeholder | `x-center-empty-state` "No active contracts end in the next 30 days" | `guardedLoad` -> `x-ui.load-error`, `retryLoad` | none (informational) | `min-h-64` |
| No-leaves employees | lazy placeholder | `x-center-empty-state` | same | none | `min-h-64` |
| Upcoming leaves | n/a (not lazy) | `x-center-empty-state` | same; `retryLoad` reloads the list | link to My leaves only when `leaves.view-own` (the route's own gate) | `min-h-64` |
| Employee notes | n/a | existing "add your first note" row (own data, no permission needed) | `x-ui.load-error` in place of the table | Add note button (header) | existing |
| Dept / Job level statistics | n/a | `x-center-empty-state` (no labels) | error; a successful retry reloads the page (their Chart.js script is pushed once at page load and would otherwise keep empty data) | none | existing |
| Salary-per / Contract-type statistics | n/a | `x-center-empty-state` (zero total) | error; retry re-renders, `lazy-charts.js` re-inits amCharts on the commit hook | none | `min-h-64` (contract-type) |
| Index: my leaves, team, upcoming events, pending requests, checklist, badges | n/a | the anonymous `x-dashboard-cards.*` components keep their own empty text (outside this task's file scope) | whole card replaced by `x-ui.load-error` | n/a | wrapper heights unchanged |

## Security

- No salary/pay figure is loaded or rendered by any dashboard card (the "salary per" cards count contracts by pay *period* only). Test asserts a 987654 salary never appears in the soon-ending card HTML.
- City/role scoping untouched: `UpcomingLeavesCard` still goes through `upcomingVisibleTo()`; a DMS HR user keeps seeing only DMS leaves after a failed load + retry (test). `Index` team visibility still goes through `Gate viewDirectory` (DirectoryAccessPolicyTest passes).
- Exception text is never shown (trait reports it and shows the shared copy).
- Soon-ending and no-leaves cards remain org-wide as before (they sit on the HR-gated general statistics page); scoping was not changed.

## Limits / follow-ups

- Index-level empty states for team / news / pending / checklist / badges live in `resources/views/components/dashboard-cards/*` (not in scope); `news-card.blade.php` queries `News` inside the Blade component, so a failure there is not caught by `Index`. Moving that query into `Index` (or a Livewire card) is the follow-up.
- The team card is the heaviest block on the page but could not be made lazy without moving `teamMembers` out of `Index`, which `DirectoryAccessPolicyTest` reads via `viewData('teamMembers')`.
- `UpcomingLeavesCard` lazy: see table.
- No browser/RTL/375px visual check was done (no browser run); the new markup uses logical utilities and the existing shared components.
- `vendor/bin/pint --dirty` also reformatted a file owned by another task (`app/Livewire/Concerns/HasScreenListStates.php`) - formatting only.
