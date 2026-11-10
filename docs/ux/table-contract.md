# B4-D · Task-focused tables and view persistence (proposed)

Status: **complete; product-owner answers recorded 2026-10-22 (§8); G-A passed; awaiting formal review** (QA engineer + technical lead). Not marked done until reviewed.
Date: 2026-10-22. Task: B4-D in [ux-prompts-v3.md](ux-prompts-v3.md) (R-16). Owner role: UX designer + HR product owner. Reviewer: QA engineer + technical lead.
Consumer: **B4** (implementation).

This is a design document. **No application code was changed.** Every column set, preset, URL parameter and component name below is **PROPOSED** until B4 implements it.

---

## 1. Starting state

| Item | Value |
|---|---|
| Revision | `hr-ux-by-rafi` = `main` @ `ed464e73` (A2, A3, A3-R, A4, A5, A5-M, A6, A7, B0, X2-I merged) |
| Dependency | **G-A: PASS WITH CONDITIONS** ([g-a-gate.md](g-a-gate.md), `2375ac49`, §7 "B1, B4-D and B6 may start"). B4-D was drafted before the gate as groundwork; rechecked after it: `git diff ed464e73..33c7ba24` touches no table component, model scope or table view, so §2–§6 stand unchanged. |
| Table package | `ramonrietdijk/livewire-tables` v6.5.4, with the app's view override `resources/views/vendor/livewire-table/livewire/livewire-table.blade.php` |

## 2. Inventory (task step 1)

### 2.1 What the package already offers

| Capability | Where | Used today |
|---|---|---|
| Column picker (show/hide, reorder) | `HasColumns` (`$columns`, `$columnOrder`, `selectAllColumns`, `reorderColumn`) | All tables (default: **every column visible**; no column calls `->hide()`) |
| Global search, per-column search | `HasSearch` (`globalSearch`, `search[...]`) | All tables |
| Sort | `HasSorting` | All tables |
| Per-page (15 default) | `HasPagination` | All tables |
| **URL sync** of search, column search, sort, filters, per-page | `HasQueryString` (`useQueryString = true` by default) | All tables, so browser Back keeps them |
| **Session memory** of chosen properties | `HasSession` (`useSession`, `sessionProperties`, default stores only columns + order) | **Only contracts**: `globalSearch`, `search`, `paginators` (page), `perPage`, per user (`ContractsTable::sessionKey()` appends `auth()->id()`) |
| Row selection, bulk | `HasSelection` | Not used for actions |
| Deferred loading | `HasDeferredLoading` | Off. The view override always adds `wire:init="init"`, so every table renders twice after load (X1 §4, bottleneck 3: ~2.0 s on `/employees`). |
| Excel export | `exportExcel()` in the tables | Exports **exactly the visible columns** (`EmployeeTable`, `ContractsTable`), so the picker also changes the export |

Layout: one bordered card with `overflow-x-auto`. Wide tables scroll sideways inside it; **there is no card or phone layout**.

### 2.2 Per table

| Table (component, route) | Who | Columns today (all visible) | Row click | Row actions | Status filter | Saved state |
|---|---|---|---|---|---|---|
| **Employees** (`EmployeeTable`, `/employees`) | HR, general manager | ID, Photo, Name, Role (account), Years of service, Joined at, City, Direct manager, Position, Work email, Active, Actions (**11 + actions**) | **Edit page** for `employees.update` holders, else none | Activate/deactivate, View profile, Edit, Delete | Active column filter | URL only |
| **Contracts** (`ContractsTable`, `/contracts`) | HR, GM, managers (own reports, read-only) | Employee, Program, Position, Contract type, City, Department, Job level, Duration, Period, Terminated at, Yearly leave days, Salary per (unit only), Weekly hours, Status, Auto-renew, Actions (**15 + actions**) | Edit page (editors), details page (view-only) | Auto-renew toggle, View, Edit, Renew, Export PDF, Delete | Status, Auto-renew column filters | URL + **session per user** (search, page, per-page) |
| **All leaves** (`AllLeaveRequestTable`, `/leaves/all`) | Managers, HR, GM | Employee, Leave type, Type (day/hour), Duration, Status, Request date, Start, End, Seen by HR, Actions (**9 + actions**) | Pending → **decision**; other → **edit** | Decide, Edit, View, HR review | Tabs All/Pending/Accepted/Rejected (module `statusFilter`, **not in the URL**) | URL (table only) |
| **My leaves** (`MyLeaveRequestTable`, `/leaves`) | Everyone with leave | Leave type, Type, Duration, Status, Start, End, Seen by HR, Actions (**7 + actions**) | Pending → edit; other → details | View, Edit, Delete | Same tabs, **not in the URL** | URL (table only) |
| **Leave balances** (`EmpLeaveBalanceTable`, `/leaves/balance`) | HR | Name, Years of service, Deserved, Used, Remaining, Actions (**5 + actions**) | Edit modal | Edit | — | URL only |

**Pay:** no list shows an amount. Contracts show only the pay **unit** ("per month"); `ContractsTable` checks `User::canViewSalary()` for pay-related actions. B4 must keep amounts out of every list, picker, export and payload.

## 3. Proposed compact defaults (task step 2, AC-B4-D-01)

Principles: one **identity** column (who/what) that also carries a secondary line; only the columns needed to **choose a row**; status as **text plus colour**; the **one main action** visible, the rest in a "More" menu; a city column only for roles that see more than one city (for a city-scoped HR officer it is the same value on every row). Everything removed from the default stays available in the column picker.

| Table | Default columns (desktop) | Moved to the picker (hidden by default) | Main action / "More" |
|---|---|---|---|
| **Employees** | **Employee** (photo, name, position on a second line) · Direct manager · Work email · Status · City *(nationwide roles only)* | ID, Role, Years of service, Joined at, separate Position column | **View profile** / More: Edit, Activate/Deactivate, Delete |
| **Contracts** | **Employee** (name, position on a second line) · Contract type · Period (start – end, "ends in N days" when ≤ 30) · Status · Auto-renew · City *(nationwide roles only)* | Program, Department, Job level, Duration, Terminated at, Yearly leave days, Salary per, Weekly hours, separate Position column | **View** / More: Edit, Renew, Export PDF, Auto-renew on/off, Delete |
| **All leaves** | **Employee** · Leave type · Dates (start – end) with duration ("3 days", "2 h") · Status (+ next actor, from A3) · Seen by HR *(HR only)* | Day/Hour column (merged into duration), Request date | Pending: **Decide**; other: **View** / More: Edit (if allowed), HR review |
| **My leaves** | Leave type · Dates with duration · Status (+ next actor) | Day/Hour column, Seen by HR (shown inside the status text) | **View** / More: Edit, Delete (while pending) |
| **Leave balances** | Name · Years of service · Deserved · Used · Remaining · City *(nationwide roles only)* | — (already compact) | **Edit** |

**Row click (decided, B4D-Q2):** opens the **read-only** view (profile, contract details, leave details), never an editor. Editing becomes a deliberate action. This avoids accidental edits and matches B4's "detail drawer".

## 4. State: what is remembered, where, and when it is reset (task step 3, AC-B4-D-02)

### 4.1 Where each piece of state lives

| State | Canonical place | Why |
|---|---|---|
| Global search, column searches, filters, sort, page, per-page | **URL** (already) | Shareable, bookmarkable, browser Back/Forward work |
| Leave status tab (All/Pending/…) | **URL**: `?status=pending` (new, PROPOSED) | Today it is lost on reload and on return |
| Named preset (§5) | **URL**: `?view=<preset>` (PROPOSED) | A preset is just a stored set of URL parameters |
| "Where I was" on returning by an in-app link (not Back) | **Server session**, per user **and active role and city scope** (like contracts today, extended) | Back already works through the URL; this covers the sidebar link back to the list |
| Column choice and order | **Server session**, per user + role (package `columns` / `columnOrder`) | Lasts for the login session; never in browser storage (no HR data in localStorage) |
| Personal saved views ("my views") | **Database**, per user (decided, B4D-Q5; rules in §5.1) | Must survive logout and devices, so neither session nor browser storage |

### 4.2 Reset rules (safe defaults when things change)

| Situation | Rule |
|---|---|
| Restored page is past the last page (rows were deleted or a filter narrows) | Go to the **last** page that exists, never an empty "page 9 of 3" |
| Restored filter value is no longer allowed (e.g. a city outside the new scope, a deleted program) | **Drop that filter** and show a one-line notice "A filter was cleared because it no longer applies" |
| **Active role or city scope changes** | Restore **nothing** from the other role's session state; start from the role's defaults. The session key includes role and city scope (contracts today use user only) |
| Column that the role may not see (e.g. HR-only "Seen by HR") | Never restored for a role that may not see it |
| URL and session disagree | **URL wins** (already the contracts rule) |
| User presses "Clear all" | Clears search, filters, sort, preset and page; keeps column choice |

Results are always re-queried through the table's authorized `query()` (`Contract::visibleTo`, `LeaveRequest::manageableBy`, city scope): a restored search can never show rows the active role may not see. The rules above are about **sensible screens**, not access.

## 5. Named presets ("views") (task step 4, AC-B4-D-03)

A small fixed set per table, chosen by HR. A preset only **narrows** the table's authorized rows; it can never widen them.

| Table | Preset | Meaning | Who sees it |
|---|---|---|---|
| Employees | **Active** *(default)* | `is_active = true` | HR, GM |
| | Inactive | `is_active = false` | HR, GM (A2-D Q3: former staff are HR/GM only) |
| | Incomplete | employee drafts (B5-D), once B5 exists | HR in scope |
| Contracts | **Active** *(default)* | running contracts | all roles with contracts |
| | Ending soon | active, end date within **30 days** (B4D-Q3) | HR, GM |
| | Terminated / ended | terminated or past end date | HR, GM |
| | Draft reservations | `type = Draft` | HR, GM |
| All leaves | **Needs my decision** *(default for managers)* | pending and `decide` allowed | managers, HR |
| | Not yet seen by HR *(default for HR)* | decided, `hr_approved = false` | HR |
| | All | everything in scope | managers, HR |
| My leaves | **All** *(default)* · Pending · Accepted · Rejected | the existing tabs, now in the URL | the employee |

Fixed presets are the same for everyone with access; HR may change the set later.

### 5.1 Personal saved views (decided B4D-Q5: "fixed + personal now")

Each user can save the table's current state under a name of their own (e.g. "Aleppo admin contracts") and reapply it with one click, next to the fixed presets.

| Rule | Proposed design |
|---|---|
| What is saved | Only the **table state**: global/column search, filters, sort, per-page, column choice and order. Never row IDs, names from results, pay figures or exports. |
| Where | A new table (PROPOSED `saved_table_views`: user, table, role context, name, state JSON, timestamps). **Needs a migration**, so B4 implementation requires separate authorization to run it. |
| Who sees a view | **Its owner only.** Sharing with colleagues is not included (it would need its own access rules); a later decision if HR asks. |
| Role / city | A view is tied to the **role context** it was saved in and only listed under that role. Applying it always goes through the table's authorized `query()`; a filter that is no longer allowed is dropped with the §4.2 notice. A view can never show more than the role may see. |
| Limits | Up to **10** views per user per table (PROPOSED); rename and delete by the owner. |
| Lifecycle | Deleted with the user account; no audit trail needed (no HR data). |
| Columns the role may not see | Ignored when applied (§4.2), e.g. "Seen by HR" in a manager's view. |

## 6. Phone representation (task step 4, AC-B4-D-01)

Below 768 px, each table becomes a **list of cards**, not a shrunk table. There is no sideways page scroll (B4's AC-B4-03).

| Table | Card: line 1 | Line 2 | Line 3 | Tap | Main action |
|---|---|---|---|---|---|
| Employees | photo + name | position · city* | work email | profile | "More" menu |
| Contracts | name | contract type · period | status badge · "ends in N days" | details | "More" menu |
| All leaves | name | leave type · dates · duration | status + next actor | decision (pending) / details | Decide |
| My leaves | leave type | dates · duration | status + next actor | details | — |
| Leave balances | name | deserved · used · remaining | — | edit | — |

Search, the preset menu and "Filters" (a bottom sheet with filter chips and Clear all) sit above the list.

### 6.1 Wireframes (EN, desktop and phone; AR mirrors them)

Employees, desktop (city-scoped HR, so no City column):

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Employees                                   [ Active ▾ ]  [Search…]  [+ Add] │
│ Filters: (Status: Active ×)  (Manager: Rami Haddad ×)        [Clear all]     │
├────────────────────────────┬──────────────────┬──────────────────┬────────────┤
│ Employee                   │ Direct manager   │ Work email       │ Status     │
├────────────────────────────┼──────────────────┼──────────────────┼────────────┤
│ (●) Mira Najjar            │ Rami Haddad      │ mira@…           │ ● Active   │
│     ICDL Trainer           │                  │      [View] [⋯]  │            │
└────────────────────────────┴──────────────────┴──────────────────┴────────────┘
  1–15 of 71    ‹ 1 2 3 4 5 ›                                   [Columns ▾]
```

Contracts, phone (375 px):

```text
┌─────────────────────────────┐
│ Contracts      [Active ▾]   │
│ [Search…]        [Filters]  │
├─────────────────────────────┤
│ Nadine Sarkis               │
│ Volunteer · 2 Nov–31 Dec    │
│ ● Active · ends in 25 days  │
│                      [⋯]    │
├─────────────────────────────┤
│ …                           │
└─────────────────────────────┘
```

Arabic (RTL): the same layout mirrored with logical properties: identity column on the right, actions and "More" on the left, the pager reversed. Names and emails stay isolated (`dir="auto"`) so Latin values do not flip the line (A4).

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ [+ إضافة]  [بحث…]  [ ▾ الفعّالون ]                                   الموظفون │
├────────────┬──────────────────┬──────────────────┬────────────────────────────┤
│ الحالة      │ البريد الإلكتروني │ المدير المباشر    │ الموظف                     │
├────────────┼──────────────────┼──────────────────┼────────────────────────────┤
│   فعّال ●  │       …@mira     │ رامي حداد        │ ميرا نجار (●)              │
│            │  [⋯] [عرض]       │                  │ مدرّب ICDL                 │
└────────────┴──────────────────┴──────────────────┴────────────────────────────┘
```

These are layout sketches for the decision, not pixel designs. B4 builds them with the existing components and brand tokens.

## 7. Notes for B4 (implementation constraints)

- **Reuse the package.** Defaults via `->hide()` on columns; picker, sort, search and URL sync already exist. A card layout is a view-level change in the table override, below `md`.
- **Session key per user + active role + city scope** for every table that remembers state (fix `ContractsTable::sessionKey()` the same way).
- **Export (decided B4D-Q6):** exports keep following the visible columns; the export button must say so ("Exports the columns shown").
- **Performance:** the `wire:init` double render (X1 bottleneck 3) belongs to X1-I, but B4 should not add a third round trip (e.g. restoring state in a separate request).
- **Authorization:** presets and restored filters go through each table's existing authorized `query()`; never a separate unscoped count.
- **Accessibility:** "More" menus and filter chips need names and keyboard support (A5 patterns); status keeps text, not colour alone.

## 8. Decisions (product owner, 2026-10-22)

Answered by the developer acting for the HR product owner (Rafi), question by question in Arabic. Q3 and Q4 were changed by the product owner after the first answer; the final answers are recorded.

| ID | Question | Today | Decision |
|---|---|---|---|
| **B4D-Q1** | Default columns per table | Every column visible | **Compact set as §3** (work email stays visible in the employee list) |
| **B4D-Q2** | What clicking a row opens | The editor (or the leave decision) | **The read-only view**; edit is a separate action |
| **B4D-Q3** | "Ending soon" threshold for contracts | No such idea | **30 days** |
| **B4D-Q4** | Default leave view | "All" for everyone; tab lost on reload | **By role**: managers start on "Needs my decision", HR on "Not yet seen by HR"; tab kept in the URL |
| **B4D-Q5** | Saved views | None (contracts remember search/page until logout) | **Fixed presets and personal saved views now** (§5, §5.1) |
| **B4D-Q6** | What the Excel export contains | The visible columns, without saying so | **The visible columns**, and the button says so |
| **B4D-Q7** | Phone layout | Full table with sideways scroll | **Cards as §6** |

Follow-ups created by Q5 (defaults in §5.1, B4 may proceed with them unless HR objects): views are **private** to their owner (no sharing); **10** views per user per table; the new table needs a **migration**, authorized separately when B4 is implemented.

## 9. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-B4-D-01** Each table has an agreed default/detail/mobile layout and action hierarchy. | **Met (decided)** | §3 defaults + picker (detail) + main/More actions; §6 phone cards; §6.1 wireframes EN/AR; agreed in B4D-Q1, Q2, Q7 |
| **AC-B4-D-02** State persistence and stale-page reset rules are documented. | **Met** | §4.1 URL vs session vs database; §4.2 reset rules incl. role/city change |
| **AC-B4-D-03** Saved view meanings and authorized scopes are explicit. | **Met** | §5 presets with meaning and who sees them; §5.1 personal views (owner-only, role-bound); both only narrow authorized rows |

Verification performed: source trace at `ed464e73` (components, package concerns, view override); no application test is claimed. **G-A: passed** (rechecked at `33c7ba24`). **Formal review: pending.** B4-D is **not** marked done.

G-A conditions relevant to B4: open item 1 (Arabic Escape after reopen) means B4 must not reuse `GuardsModalClose` in a detail drawer until it is checked; open item 4 (13 reference-table actions with only the coarse permission) is not a table-list concern but should not be copied into B4 actions.
