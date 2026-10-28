# G-C source audit (read-only)

Repo `C:\laragon\www\hc-hr`, `main` @ `aa983edc`, 2026-10-27. Nothing in the repo was modified, no test was run, no browser was used. Every finding comes from reading the source and is reproduced with **synthetic** data only. Severity: blocker / high / medium / low / info.

---

## 1. Prerequisite check (C1–C5)

| Task | Note | Status in the note | ACs and the evidence claimed | Do the claimed tests/classes exist? | Open / handed on |
|---|---|---|---|---|---|
| C1 | `docs/ux/c1-implementation.md` | "built and tested; **review pending**" | AC-C1-01, 02, 03 → `NavigationAreasTest` (doc says 7 tests; the file has **8**, the 8th being G-B-13's `test_reference_pages_left_out_of_the_menu_are_refused_by_url`), plus `LeaveRequestWorkflowTest::test_c1_work_inbox_counts_pending_requests_of_the_managers_team`. Browser: Arabic only, manager.alp, 1280×800 and 375×667. | All 9 named tests found. `App\Support\Navigation`, `Sidebar::$currentRoute` and `Breadcrumbs::$area` exist. | English not browser-checked (G-B-12). HR and super-admin menus were checked by the tests only. The interim inbox count described in §1 has been **superseded** by C3 (stale text). X2-R runs the C0 scenario check. |
| C2 | `c2-implementation.md` | "built and tested; **review pending**" | AC-C2-01/02/03 → `GlobalSearchTest` (10 tests). Browser: Arabic, manager.alp. | All 10 found. `GlobalSearch`, `SafeReturn`, `RemembersReturnTo` and `Navigation::newActions()` exist. | Ctrl+K and English were **not confirmed** in the browser. Contracts are not searchable (deferred). |
| C3 | `c3-implementation.md` | "built and tested; **review pending**. Needs `php artisan migrate`." | AC-C3-01/02/03 plus the decision rules → 13 `test_c3_*` in `LeaveRequestWorkflowTest` and 5 in `WorkInboxChecklistTest`. Two browser passes (Arabic, manager.alp / hr.alp). | All found. `WorkInbox`, `LeaveDecisionAuthority`, `LeaveRequestReview`, `Inbox\Index` and the migration exist. | English not checked in the browser. The input contract C3-D (`approval-source-contract.md` §10) is "**Formal review: pending**". G-B's formal review is also pending (`g-b-gate.md:114`). The 100-row cap per source is documented and silent (see F5). |
| C4 | `c4-implementation.md` | "built and tested; **review pending**" | AC-C4-01/02/03 → `ForYouNowTest` (6 tests), `UpcomingLeavesCardTest` (3) and `DashboardCardStatesTest` (12). Browser: hr.alp and manager.alp, Arabic. | All found. `Contract::scopeInView` exists and is used by both `ContractsTable::applyPreset` and `ForYouNow`. | The **employee persona, the general manager and English were not browser-checked**. The note names **G-C as consumer** ("task checks per role"). |
| C5 | `c5-implementation.md` | "built and tested; **review pending**" | AC-C5-01/02/03 → `TeamCoverageTest` (19 tests, e1…e14 personas). Browser: Arabic, manager.alp and hr.alp. | All 19 found. `TeamCoverage`, `Team\Calendar` and `config/team-coverage.php` exist. | The **requester hint and the manager warning were not browser-checked**, and neither was English. The input contract C5-D (`team-coverage-contract.md`) is "Technical-lead review of §4 and §6 **pending**". The "Dashboard card unchanged" line in §5 is **stale**: C4 already replaced it with TeamCoverage. Holidays T-1 (per-project and birthday) are not built. |
| C0 | `c0/scenario-check.md` | User-test guide | — | — | The results table (§5) is **empty**: no participant data. Under AC-G-C-01 this is a limitation, not a success. |

No AC in C1–C5 is marked "not met". All five carry "review pending", so none is formally done. The full-suite counts quoted (946 → 956 → 974 → 997 → 1004, 0 failures) are self-reported and were not re-run here.

---

## 2. Where each actionable count is computed (AC-G-C-02)

| Surface | file:line | Query / service |
|---|---|---|
| Sidebar "Work inbox" badge | `app/Support/Navigation.php:277-298`, rendered from `Livewire/Layout/Sidebar.php:84` | `WorkInbox::count()` = `rows()->count()` |
| Breadcrumbs (computes it too, unused) | `Livewire/Layout/Breadcrumbs.php:31` → `Navigation::areaFor` → `for()` with `countInbox=true` | the same, computed a second time (F16) |
| Inbox page tabs (all / decide / review / checklist) | `Livewire/Inbox/Index.php:216-228` | `WorkInbox::rows()` |
| Home "For you now", HR / super-admin | `Livewire/Dashboard/ForYouNow.php:106-125` | `WorkInbox::rows()` by kind |
| Home "Awaiting my decision", manager / GM | `ForYouNow.php:89-103` | `WorkInbox::rows()->where('action','decide')` |
| Inbox rows, leave decide | `Services/WorkInbox.php:66-78` | `manageableBy` + pending + active requester, `limit(100)`, **then** `Gate decide` (`LeaveDecisionAuthority::basisFor`) |
| Inbox rows, leave review | `WorkInbox.php:81-94` | isHr + `leaves.review` + `manageableBy` + not pending + `hr_approved=false` + active, limit 100, `Gate review` |
| Inbox rows, checklist deliver/receive | `WorkInbox.php:97-125`, `156-162` | `delivered_id/receiver_id = me` + pending + **employee.is_active** + permission |
| Inbox rows, checklist finalize | `WorkInbox.php:128-144` | isHr + `employees.update` + city + `returned_status=received` + active |
| "Hand out" sidebar counter | `Livewire/ChecklistItems/PendingDeliversCounter.php:21-23` | `delivered_id = me`, pending (**no is_active**) |
| "Take back" sidebar counter | `PendingReturnsCounter.php:21-23` | `receiver_id = me`, `returned_status` pending (**no is_active**) |
| Sidebar's own delivers/returns counts | `Sidebar.php:60-69` | computed but never rendered (dead code, F16) |
| Home "pending delivers" card | `Livewire/Dashboard/Index.php:318-337` (card at `index.blade.php:93`) | `delivered_id = me`, pending (no is_active, no permission check) |
| Home "my items" (employee) | `ForYouNow.php:213-228` | `employee_id = me`: delivered pending / returned pending |
| All leaves / Team leaves tabs | `Livewire/Employee/Leave/AllLeaveRequestModule.php:240-256` | `manageableBy` by status (no is_active filter, no decide filter) |
| Notification "awaiting" state | `Services/NotificationRequestState.php:89-97` | `Gate view/decide/review`, the same policy as the inbox |
| Home "Contracts ending in 30 days" | `ForYouNow.php:231-240` | `Contract::visibleTo()->inView('ending-soon', 30)` = the same as `ContractsTable.php:266,312` |
| Statistics "Soon-ending contracts" card | `Livewire/Dashboard/SoonEndingContractsCard.php:35-44` | **unscoped**, its own filters (F14) |
| Home "Awaiting contract / incomplete" | `ForYouNow.php:243-265` | the same scope as `EmployeeTable.php:204-224` |
| Home "Who in my team is off" | `ForYouNow.php:128-150` | `TeamCoverage::absences('reports', today…today+6)`, all statuses |
| Home "Upcoming leaves" card | `Dashboard/UpcomingLeavesCard.php:31-38` | `TeamCoverage::absences(defaultScope, today…+61, accepted)` |
| Team calendar day counts | `Team/Calendar.php:88` → `TeamCoverage::coverage` | the visible set only |
| Requester hint / manager overlap | `TeamCoverage.php:198-213`, `245-304` | the same service |

**Checked, consistent:**
- The sidebar badge, the inbox "all" tab and the HR home figure use the same `WorkInbox::rows()` from the active role in the same request.
- The home decision count equals the inbox `?show=decide` tab.
- Contracts ending (home) equals the contracts table's `ending-soon` view.
- Awaiting and incomplete (home) equal the employees table's views.
- The notification state uses the same `decide`/`review` policy.
- The calendar, the requester hint and the upcoming card share `TeamCoverage`.
- HR with two cities (`HR-HMS`: Homs + Homs Rural via `City::slugsForHrRole`) is handled by `activeCityIds()` everywhere.
- A role switch recomputes everything: there is no cross-request cache, and the `TeamCoverage` memo is per instance, keyed by user and role.
- super-admin is nationwide on every surface.

---

## 3. Findings

### F1 — HIGH — A pending request can sit in **no one's** inbox: the direct manager exists but cannot act, and HR may not stand in
- **Where:** `app/Services/LeaveDecisionAuthority.php:44-58`, `67-84`, and `WorkInbox.php:66-78`.
- **What the code does:** `directManagers()` lists every active holder of the manager position, whatever their account, role or permission. HR gets `NO_MANAGER` only when that list is empty, and `ON_BEHALF` only when every holder is on accepted leave. A holder who cannot decide therefore blocks HR, while the holder sees nothing either.
- **Repro (synthetic):**
  - Employee E (Aleppo, active). E's position P_E has `direct_manager_id = P_M`.
  - P_M is held on an active contract by M, active. M has either (a) **no user account**, or (b) an account with only the `employee` role, which lacks `leaves.decide` (`config/access-control.php:76-81`), or (c) the roles manager and employee with **employee** active.
  - E submits a leave request.
  - M's inbox has 0 rows (`leaves.decide` fails; in case (a) M cannot log in).
  - HR-ALP's inbox has 0 rows: `basisFor` returns null because the manager exists and is not on leave. HR-SYR and super-admin: also 0.
  - HR-ALP's *All leaves → Pending* tab shows 1 (`AllLeaveRequestModule.php:249`).
  - The notification sent to M (`LeaveNotificationService::managerRecipients`, `:135-147`) is "not actionable" under `NotificationRequestState`. In case (a) no notification is sent at all.
- **Variant (d), cross-city manager:** user U holds P_M but has only the role HR-ALP, and E is in Damascus. `Gate decide` passes (MANAGER basis). However, `manageableBy(HR-ALP)` excludes Damascus, so the row is not listed and `Gate view` fails. `Inbox\Index::decide` → `leaveFor` returns `not_allowed`.
- **Result:** the "pending decisions" count is 0 on every inbox while All leaves shows 1 pending. This is a contradictory actionable count. The request can only be decided after a data or role fix.
- **Tests:** none of the `test_c3_*` cover a manager without `leaves.decide` or without an account (the fixtures grant `leaves.decide`, e.g. `LeaveRequestWorkflowTest.php:438,1410`).
- **Needs a policy decision:** treat "no holder who can decide" as `no_manager`, or flag the request for HR.

### F2 — HIGH — HR can decide its **own** leave request (on behalf of its own manager, or as "no manager")
- **Where:** `LeaveDecisionAuthority::basisFor` (`:38-59`), `LeaveRequestPolicy::decide` (`:40-44`), `LeaveRequestForm::submitDecision` (`:246-263`). None of them checks requester ≠ decider. `basisFor` returns `MANAGER` only when the user holds the manager position, so the requester's own position never reports to itself, but the HR branches never exclude self.
- **Repro:**
  - HR officer L (role HR-ALP, Aleppo) holds a position whose `direct_manager_id` is the HR head position, held by H.
  - H has an accepted leave covering today. L submits a request.
  - L's own inbox lists it as `leave:{id}:decide`, "on behalf of H". The count includes it and L can accept it; `decision_basis = on_behalf`.
- **Same for the top of the tree:** HR-SYR's head has no `direct_manager_id`, so `basisFor` returns `NO_MANAGER` and the head approves their own leave.
- **Background:** this predates C3, since HR could decide everything before. DEC-05 rev. ("only the direct manager decides; HR on behalf…") does not address self-decision, but separation of duties is broken on a C3 surface.
- **Needs a decision:** whether HR-SYR or another HR officer should take these.

### F3 — MEDIUM — The inbox's `markSeen` and `object` skip the `leaves.review` permission that the old screen enforces
- **Where:**
  - `config/access-control.php:553` lists `inbox.index` → `markSeen`, `object` as exempt.
  - `LeaveRequestPolicy::review` (`:57-63`) and `object` (`:50-55`) check only "is HR + `manageableBy`" and **no permission**.
  - `LeaveRequestReview::markSeen/object` (`Services/LeaveRequestReview.php:39,65`) authorize only through those policies.
  - The old path `AllLeaveRequestTable::markAsReviewed` is mapped to `leaves.review` (`config/access-control.php:239`).
  - `WorkInbox::leaveReviews` hides the rows without `leaves.review` (`WorkInbox.php:83`).
- **Repro:**
  - Remove `leaves.review` from HR-ALP in access management.
  - The inbox shows 0 "to review" rows, and All leaves → "Mark reviewed" returns 403.
  - A direct Livewire call `inbox.index::markSeen('leave:12:review')` or `::object('leave:12:review')` (with a note) succeeds: `leaveFor` checks only `Gate view`, then the policy, which ignores the permission.
  - An `object` call sends an accepted request back to pending and **returns the deducted days** to the balance.
- **Result:** server authorization depends on what the menu shows.
- **Fix direction:** require `leaves.review` in both policies, or map both methods in `livewire`.

### F4 — MEDIUM — Checklist work for former staff leaves the inbox but stays on the old pages, counters and home card
- **Where:**
  - `WorkInbox.php:156-162` adds `employee.is_active = true` to the deliver, receive and finalize rows.
  - `PendingReturnsCounter.php:21-23`, `PendingDeliversCounter.php:21-23`, `ChecklistItems/PendingItems.php:26-30` and the home "pending delivers" card (`Dashboard/Index.php:318-327`) do not.
- **Repro:**
  - A volunteer V's contract stops auto-renewing: `ContractObserver:156` → `ChecklistWorkflow::requestBelongingsReturn` sets laptop L to `returned_status = pending`, receiver R (IT).
  - V is then deactivated.
  - R's sidebar "Take back" counter shows **1**, while R's inbox and its badge show **0**.
  - For HR-ALP, its own home "For you now" lists "items 0", yet the pending-delivers card on the same home shows the hand-out.
- **Why it matters:** collecting equipment from a leaver is the main case for S4, and the inbox drops exactly those items.
- **Spec:** `approval-source-contract.md` §5 says "Former staff … never appear". Applied to S3/S4, that clashes with the return workflow, and §3.3's own S3/S4 scope has no `is_active` condition.
- **Needs a decision:** whether returns from former staff belong in the inbox.

### F5 — MEDIUM — A silent 100-row cap per source, with the cap applied **before** the decide filter
- **Where:** `WorkInbox.php:31-32`, `74`, `89`, `110`, `119`, `140`. No UI says "100+" (no `PER_SOURCE` reference in any view).
- **Repro, review backlog:**
  - HR-SYR (or super-admin) has 140 decided requests nationwide with `hr_approved = false`, for example decisions made before the review habit started. `hr_approved` defaults to false (`basic_tables.php:318`).
  - The badge, the home figure and the inbox say "to review 100", while the All leaves list holds 140 unreviewed.
- **Repro, decide rows:**
  - HR-SYR's `manageableBy` covers every city's pending requests. Suppose 120 are pending, ordered oldest first, and only #110 has no manager.
  - The query is limited to the first 100 and *then* filtered by `basisFor`, so #110 never appears. The count is 0, but the request is decidable.
- Documented in c3 §6 as "counts … would be capped", but nothing on screen tells the user.

### F6 — MEDIUM — "My team" means two different sets for a manager: Team contracts still uses the technical-manager line
- **Where:** `Contract::scopeVisibleTo` (`app/Models/Contract.php:197-210`) still has `orWhereIn('tech_manager_id', …)`. C1 puts that page in the manager's *My team* as "Team contracts" (`Navigation.php:108`). *Team leaves* (`manageableBy`, `LeaveRequest.php:185-201`), the calendar's `reports` scope (`TeamCoverage.php:341`) and the inbox use direct reports only (DEC-05 rev.: the technical manager no longer exists).
- **Repro:**
  - Manager M holds position P_M. X's position has `tech_manager_id = P_M` and `direct_manager_id = P_Other`.
  - Under *My team*, M sees X's contract in "Team contracts", but X is in neither "Team leaves", nor the calendar's "My direct team", nor M's inbox.
- **Note:** the role baseline comment at `access-control.php:67-68` still says "direct/technical reports".
- **Not checked:** whether the technical-manager line in the contract drawer exposes anything beyond what the manager already saw.

### F7 — LOW — The home "off this week" window is not the week that its link opens
- **Where:** `ForYouNow.php:136-148` reads `today … today+6` and links to `team.calendar?view=week`. `Calendar.php:140-143` shows **Sunday–Saturday** of the current week.
- **Repro:** today is Thursday 2026-10-29, and a report has an accepted leave on Sunday 2026-11-01. The home page lists it under "later this week"; the linked week view (25–31 Oct) does not show it.

### F8 — LOW — On the same home page "who's off" includes pending and "Upcoming leaves" does not
- **Where:** `ForYouNow::partTeamOff` (no status filter, so pending entries appear with a dashed chip) against `UpcomingLeavesCard` (`['status' => 'accepted']`).
- **Repro:** a report has a *pending* leave tomorrow. The manager's "For you now" shows it (marked pending), while the card below shows nothing.
- **Assessment:** this follows C5-D Q2 (card accepted only; manager coverage accepted + pending, marked). It is consistent with the decision, but a reviewer may read it as a contradiction. Recorded so G-C can state that it was accepted.

### F9 — LOW — "For you now" for employee and manager roles never shows their checklist inbox rows
- **Where:** `ForYouNow::plan` (`:77-83`). The manager plan's `decisions` part counts only decide rows, and the employee plan has no inbox part. The sidebar badge (`WorkInbox::count`) includes deliver and receive rows.
- **Repro:** an IT officer with the role `employee` and a direct grant of `checklists.deliver` has 3 hand-outs.
  - The badge shows 3. "For you now" shows the balance and my requests, nothing about the hand-outs.
  - Without a leave balance or contract the block even says «لا شيء بانتظارك الآن» while the badge says 3.

### F10 — LOW — A former employee's pending leave: 0 in the inbox, 1 in Team leaves, still decidable
- **Where:** `WorkInbox::leaveQuery` (`:151`) filters on `is_active`. `AllLeaveRequestModule::refreshCounts` (`:243-251`) and `basisFor` / `decide` do not.
- **Repro:**
  - E submits a request and is deactivated before M decides.
  - M's badge and "Awaiting my decision" show 0, while Team leaves → Pending shows 1.
  - `Gate decide` still allows it. The request keeps reserving balance (DEC-03).

### F11 — LOW — Calendar visibility levels and decision authority for "reports" come from positions, not from the active role
- **Where:**
  - `TeamCoverage::levelFor` (`:393-395`) gives manager level (pending, real sick or maternity type, hours, substitute) to whoever holds the manager position, whatever their active role.
  - `scopesFor` offers `reports` to anyone with a position (`:76-78`).
  - `team-coverage-contract.md` §4.1 says rights come "from the active role", and its matrix gives `employee` → "own direct reports n/a".
- **Repro:** user M has the roles manager and employee and switches to **employee**. The team calendar → "My direct team" still shows pending requests and real types. The inbox (which requires `leaves.decide`) shows none.
- **Assessment:** the person is the actual manager, so the exposure is small, but the behaviour differs from the contract's wording.

### F12 — LOW — The decision overlap warning can show pending absences from another city to HR deciding on behalf
- **Where:** `TeamCoverage::teamOverlap` (`:245-304`) lists names and **pending/accepted status** of the requester's direct-manager team, whatever the viewer's city. It is shown in the inbox (`Inbox/Index.php:247`) and in the decision dialog (`AllLeaveRequestModule.php:463-476`).
- **Repro:**
  - Manager M (Aleppo) has reports E1 (Aleppo) and E2 (Damascus). E2 has a *pending* leave.
  - M is on leave today, and HR-ALP decides E1's request on M's behalf.
  - The warning shows "E2 · pending · dates". Matrix §4.1: for other cities HR-<city> sees only the colleague set, at colleague level (accepted only).

### F13 — LOW (pre-existing pattern, copied by C4) — An empty city list is treated as "nationwide"
- **Where:** `ForYouNow.php:249-250`, `->when($user->activeCityIds(), …)`: an empty array is falsy, so no filter is applied. `EmployeeTable.php:200,214-217` uses the same pattern, so the home figure and the table agree with each other. Both contradict `User::activeCityIds()` (`User.php:236-238`: "An empty array means a regional role whose city does not exist, which sees nothing rather than everything").
- **Repro:** create the role HR-XYZ with `employees.view` and no matching city slug. "Awaiting contract / incomplete" then counts, and the table lists, every city.
- `WorkInbox` (`:139`) and `TeamCoverage` (`?: [0]`) handle the empty list correctly.

### F14 — MEDIUM (pre-existing, outside C1–C5, affects count consistency) — Two different "contracts ending in 30 days"
- **Where:** `SoonEndingContractsCard.php:35-44` on *Statistics → General* (`general-statistics.blade.php:26`) has **no `visibleTo`** (no city scope). It also skips the Draft-type and `termination_reason_id` exclusions that `Contract::scopeInView` applies (`Contract.php:164-179`).
- **Repro:** HR-ALP (if it holds `statistics.view`, which puts Statistics in its HR operations menu) has 8 contracts ending in its city and there are 5 in Damascus.
  - Home: "8 contracts". Contracts → ending soon: 8.
  - Statistics card: the next 5 nationwide, which can include Damascus names, positions and end dates.
- **Result:** a city-scope leak plus a contradictory figure.

### F15 — LOW — Search can label a never-employed volunteer as "(former)"
- **Where:**
  - `GlobalSearch.php:136-138` keeps anyone with *any* contract (Draft type included) in a visible position.
  - `DirectoryAccess::constrain` gives HR every inactive person in its cities.
  - The view marks every `! is_active` person as "(former)" (`global-search.blade.php:121-122`).
- **Repro:** a complete volunteer awaiting a first contract, with a Draft-type contract on a visible position, appears in HR-ALP's search as «… (سابق)».
- **Rule broken:** the shared rule requires lifecycle draft, inactive and Draft-type to be told apart.

### F16 — INFO / performance
- On every page load `WorkInbox::rows()` runs in the Sidebar, and again in `Breadcrumbs::mount` (`Navigation::areaFor` → `for()` with `countInbox` defaulting to true, though only the labels are needed). On the home page it runs a third time in `ForYouNow`.
- Each HR decide row runs `basisFor` (several queries). On top of that, `Inbox\Index::render` re-runs `find`, `basisFor` and `teamOverlap` per visible row.
- `Sidebar.php:60-69` computes two counts that no view renders.

### F17 — INFO
- `Navigation::pages()` (`:176-181`) switches `app()->setLocale()` without `try/finally`: an exception in `for()` leaves the request in the wrong locale.
- `decided_on_behalf_of` keeps the first manager only (documented in C3).
- The neutral leave types are a fixed list of ids (`config/team-coverage.php`). A future sensitive type would be shown by name to colleagues until it is added.

---

## 4. Privacy and server authorization

### Team calendar (C5)
Checked against `team-coverage-contract.md` §3.1 / §4.1 / §4.4 and DEC-06.

| Check | Result |
|---|---|
| The projection (`TeamCoverage::project`, `:552-592`) never carries reason, reject reason, HR note, balance or pay | Checked, consistent. |
| Colleague level gets an opaque key, no request id, no status, no hours | Checked, consistent. |
| Neutral type for sick, maternity, paternity and bereavement | Checked, consistent. |
| Pending dropped below manager level | Checked, consistent. |
| Rejected requests never loaded (`leavesOf` reads Accepted and Pending only) | Checked, consistent. |
| Former staff excluded (`activeContracts`, `is_active`) | Checked, consistent. |
| Photo through `employee->avatar_url`; Directory columns only | Checked, consistent. |
| Only scalar public properties | Checked, consistent. |
| URL values `scope`, `status`, `city` and `view` re-validated in `render()` (`Calendar.php:76-83`) | Checked, consistent. |
| Status filter only for manager level and above | Checked, consistent. |
| Regional HR: own city at HR level, other cities only through the colleague set | Checked, consistent. |
| general-manager: manager level org-wide | Checked, consistent. |
| HR-SYR and super-admin: HR level | Checked, consistent. |
| Every count over the visible set only | Checked, consistent. |
| Exceptions | F11 and F12 above. |

### Inbox rows (C3)
- No reason, pay or national id: the row fields are at `WorkInbox.php:169-206`.
- No avatar is rendered.
- The city is shown only to roles that span cities (`Index.php:258`).
- Checked, consistent.

### "For you now" (C4)
- No pay.
- The balance shown is the viewer's own only.
- Inbox preview rows are reduced (`inboxRow`, `:295-306`).
- The manager's team-off part is at manager level, as the matrix allows.
- Checked, consistent.

### Search and quick actions (C2)
- `select(DirectoryAccess::COLUMNS)`: no national id, personal contact, dob or salary is loaded.
- `DirectoryAccess::constrain` applies the DEC-01 directory tier.
- The profile page re-checks the tier when opened.
- *New → Contract* requires `contracts.create` **and** `User::canViewSalary()` (`Navigation.php:214`), so the salary-blind super-admin never sees it.
- Checked, consistent. Exception: F15.

### Public Livewire actions in C1–C5

| Component | Action | Server check | Verdict |
|---|---|---|---|
| `layout.sidebar` | `toggle`, `close`, `refreshCounts` | UI only; `$currentRoute` is client-writable and only affects highlighting | OK |
| `layout.global-search` | `updatedQ` | Rate limit; results from `constrain` and the menu | OK |
| `team.calendar` | `move`, `goToday`, `openDay` | Period only; scope and status re-checked in `render` | OK |
| `inbox.index` | `setFilter` | Value allow-list | OK |
| | `decide` | `Gate view`, then `submitDecision`: lock, then `Gate decide` (`leaves.decide` + `basisFor`); status validated as `in:1,2` | OK (but see F1 and F2) |
| | `markSeen`, `object` | `Gate view`, then lock, then `Gate review` / `object`, **without `leaves.review`** | **F3** |
| | `confirmItem`, `applyBatch` | Permission + `employee_id`; `ChecklistWorkflow` re-scopes to the actor; the batch is intersected with the user's current eligible rows (`eligibleSelection`), capped at 50 | OK |
| | `finalizeItem` | `Gate update` on the employee (`employees.update` + city) + scoped `finalizeReturn` | OK. It is broader than the listing (a general manager with `employees.update` can finalize by direct call, though no row is shown), but equal to the employee edit screen. |
| | `previewBatch` | Writes nothing | OK |
| `dashboard.for-you-now` | none | — | OK |
| `RemembersReturnTo` | `returnTo` | `#[Locked]`; `SafeReturn` accepts internal paths on allow-listed routes only | OK |

**City scoping:**
- Leave rows go through `manageableBy`, whose HR branch uses `activeCityIds`.
- Checklist finalization goes through `activeCityIds` + `EmployeePolicy::update`.
- Contracts go through `visibleTo`.
- `serviceDepartments` uses `Position::inCity` (`TeamCoverage.php:451`).
- No C1–C5 dropdown/validation pair was found that bypasses `Position::inCity`. These components have no position dropdowns.

**Active role:**
- Everything reads `myRole()`, `activeRoleIs()` or `hasActivePermission()` per request.
- `Navigation::isDeliverResponsible` uses `contracts()->first()` (any contract, not the active one), the same as the old sidebar.

---

## 5. Bilingual search (C2)

- **People:** `first_name_en`, `last_name_en`, `first_name_ar` and `last_name_ar` are matched **explicitly** (`GlobalSearch.php:141-145`), not through `transColumn`. Every typed word must match one of the four columns.
- **Arabic folding:** the variant letters are folded on both sides (`foldedColumn`: nested `REPLACE` on MySQL and SQLite). Diacritics are dropped on the typed side only (documented limit).
- **Wildcards:** `%`, `_` and `\` are stripped.
- **Pages:** each page is matched on `label_en` **and** `label_ar` from `Navigation::pages()`, whatever the interface language.
- **Verdict:** checked, consistent with the C2 promise.
- **Arabic digits:** C2 does not promise digit handling, and none is implemented (no `en_digits`). Names contain no digits, so there is nothing to fix. Recorded as "not promised".
- **Ordering:** alphabetical by first name in the interface language (documented).
- **Performance:** nested `REPLACE` on unindexed columns means one full scan per keystroke (after the 300 ms debounce, 60 per minute limit). Acceptable at the current scale.

---

## 6. Summary for the gate

**Highs, contradictory or unsafe decision state:**
- **F1:** a pending request can be in nobody's inbox (manager without account, `employee` role, or switched role) while All leaves shows it as pending.
- **F2:** HR can decide its own request (on behalf, or with no manager).

**Mediums:**
- **F3:** the inbox's review and objection skip the `leaves.review` permission.
- **F4:** returns from former staff are missing from the inbox but present on the counters.
- **F5:** a silent 100-row cap, applied before the decide filter.
- **F6:** "Team contracts" still follows the technical-manager line.
- **F14:** the unscoped Statistics card for contracts ending soon (pre-existing).

**Lows / info:** F7–F13, F15–F17.

**Prerequisites:**
- All five tasks are "review pending". The C3-D, C5-D and G-B formal reviews are pending.
- C0 user-test results are empty.
- English was not browser-checked for any C task. The C4 employee and general-manager personas and the C5 hint and warning were not browser-checked.
- Every test named in the notes exists.
