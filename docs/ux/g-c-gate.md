# G-C: Navigation, inbox and role dashboards gate

Task G-C in [ux-prompts-v3.md](ux-prompts-v3.md) (R-06, R-07, R-08, R-09, R-10, R-12). Version 1.0, 2026-10-27.
Integrated SHA: **`aa983edc`** (`main`: C1, C2, C3, C5, C4 on top of G-B). No product code, schema or data was changed by G-C; the only repository changes are this file, `evidence/g-c/` and one `.claude/launch.json` entry.
Prerequisites: C1–C5 notes exist and every test they name exists ([evidence/g-c/source-audit.md](evidence/g-c/source-audit.md) §1). **None of C1–C5 (nor C3-D, C5-D, G-B) has its formal review recorded**; G-C does not stand in for those reviews.

**Gate result (2026-10-28): CORRECTIONS BUILT, MATRIX RE-RUN PENDING.** Originally *needs correction*; G-C-1 to G-C-13 are fixed or accepted (§4), G-C-14 and F11 remain low. Navigation, search, counts across surfaces and privacy held in every run. Two decision-authority defects (G-C-1, G-C-2) must be fixed or explicitly decided before Phase C is accepted; neither writes bad data on its own, but G-C-1 leaves real requests with no possible decider and G-C-2 allows self-approval.

## 1. Evidence

| Source | What | Where |
|---|---|---|
| Full suite | `vendor/bin/phpunit`, PHP 8.5, SQLite `:memory:`, at `aa983edc`: **1004 tests, 5638 assertions, 0 failures, 0 errors** (G-B: 930/930) | run 2026-10-27 |
| Source audit | Prerequisites, every count source, privacy, server checks, bilingual search (read only) | [evidence/g-c/source-audit.md](evidence/g-c/source-audit.md) |
| Browser walkthrough | Moderator runs on a fresh synthetic SQLite copy (`C:/laragon/tmp/hc-hr-gc`, launch entry "hc-hr G-C (synthetic SQLite copy)", port 8402), built-in Chromium, desktop pane size and 375×667 | [evidence/g-c/browser-walkthrough.md](evidence/g-c/browser-walkthrough.md) |

Accounts and data are synthetic (`*@example.test`). The operational database and `.env` were not touched.

**Independence:** the audit and the walkthrough were run by AI agents in the same workspace that implemented C1–C5, not by an independent QA team; the review (§6) should treat them as a first pass.

## 2. Task success and findability against X2 (AC-G-C-01)

**This is a moderator walkthrough, not participant sessions.** The X2 baseline ([usability-baseline.md](usability-baseline.md)) and the C0 user-test table hold **0 participant observations**, so no time, SEQ, SUS or before/after improvement is reported. Outcome codes and end states follow [x2/task-scripts.md](x2/task-scripts.md).

| Task | Run | Persona | Outcome | Entry point found by | End state |
|---|---|---|---|---|---|
| A · Request leave | EN desktop | staff ALP | **S** | dashboard / menu | request 70, pending |
| A · Request leave | AR phone | staff HMS | **S** | dashboard / menu | request 71, pending (see G-C-1) |
| B · Find an employee | EN desktop | HR ALP | **S** | header search (C2) | correct single result |
| B · Find an employee | AR phone | HR HMS | **S** | header search (C2) | correct single result |
| C · Review a request | EN desktop | manager ALP | **S** | "For you now" (C4) → inbox (C3) | request 64 rejected with reason |
| C · Review a request | AR desktop | manager HMS | **S** | "For you now" → inbox | request 67 rejected with reason |
| D · Create a contract | EN desktop | HR ALP | entry only | New → Contract (C2) | composer not completed; **no outcome code** |

C0 scenarios T1–T8: every entry point was reached from navigation, the dashboard or search **without typing a URL, in 0–2 clicks**. T9 not run (no admin persona).

**Not covered:** Task D end state (incl. G-B-10 auto-renew box, reservation upgrade); A EN phone, A AR desktop, B EN phone, B AR desktop, C EN phone, C AR phone; HR-HMS in English; general manager and super-admin personas; Ctrl+K; screen reader, real phone, on-screen keyboard, 200 % zoom; MySQL. These are limitations, not passes, and carry to X2-R.

## 3. Consistency and privacy matrix (AC-G-C-02)

| Check | Result |
|---|---|
| Menu badge = inbox tabs = dashboard "For you now" (all personas walked) | **Consistent.** All read the same `WorkInbox` rows and policies (audit §2); browser numbers matched in every run, including 5 → 8 for HR-ALP once both direct managers were put on leave today (on-behalf state). |
| Calendar "away today" = Upcoming leaves card | **Consistent** (3 → 4 HR-ALP, 1 HR-HMS); both use `TeamCoverage`. |
| Contracts ending / awaiting contract / incomplete records vs. their tables | **Consistent** (audit §2). |
| Notification bell vs. inbox | Different by design (unread notices, not pending work); see G-C-12. |
| Role switch, HR with two cities, super-admin | Recomputed per request; nothing cached across requests. |
| Calendar privacy | No reason, note, balance or pay; colleagues see accepted leave only and "On leave" for sensitive types; disallowed scopes fall back to an allowed view. |
| Inbox, dashboard, search privacy | No reason, pay or national id; search selects only `DirectoryAccess::COLUMNS`; New → Contract also needs `canViewSalary()`; photos via `avatar_url`. |
| Direct URL / Livewire calls to another city's record or a non-report | **Refused** in every probe (403 / "not allowed"). |
| Two sessions, same request (65) | HR decided on behalf first; the manager's later reject was refused with "already accepted by …"; **one write**. |
| Role switch on a stale inbox page (66) | Refused; request stayed pending. |
| Bilingual search | Arabic name in EN UI, English name in AR UI, «ساره» → «سارة» all found. C2 never promised Arabic-Indic digits. |

**No private calendar/profile exposure was observed.** Count contradictions were observed only in the edge cases listed as G-C-1, G-C-5–G-C-8 and G-C-11 below; the main surfaces agree.

## 4. Findings and disposition (AC-G-C-03)

Ids: `F` = source audit, `D` = browser walkthrough.

| # | Finding | Severity | Disposition |
|---|---|---|---|
| G-C-1 (F1, D-01) | **A pending request can have no possible decider.** If the direct-manager position is held by someone who cannot decide (only `employee` role, no account, switched role, HR role of another city) and is not on leave, HR may not stand in (`LeaveDecisionAuthority::basisFor`, lines 44–58). Every inbox shows 0 while All leaves → Pending shows it; the employee is told "waiting for the HR team". Seen in the browser on request 71; the synthetic copy has ≥ 10 such requests across cities. | **High** | **Decided and built (2026-10-27)**, [decisions.md](decisions.md) DEC-05 revision 2. The direct-manager position holder sees and decides their reports' requests in the work inbox whatever their role (`LeaveRequest::scopeManageableBy`, `LeaveDecisionAuthority::basisFor`, `WorkInbox`); no escalation, HR on behalf only while the manager is on leave. The new-request notice opens the inbox for someone without *All leaves*, and the next-step text names that manager. `leaves:send-decision-reminders` (daily 08:05) sends one in-app reminder to the manager and city HR 3 days after submission or 1 day before the start. Tests `test_gc1_*` (`LeaveRequestWorkflowTest`); a manager position holder with **no login account** still cannot act (HR creates the account). |
| G-C-2 (F2) | **HR can decide its own leave** "on behalf" of its own manager on leave, or as "no manager" at the top of the tree; nothing compares requester and decider. | **High** | **Fixed (2026-10-27).** `LeaveDecisionAuthority::basisFor()` returns no basis when the requester is the user, on every basis; the policy, the decision form and the inbox all go through it. Tests `test_gc2_hr_cannot_decide_its_own_request_as_no_manager` and `…_on_behalf_of_its_absent_manager` fail without the change; another HR person in scope still stands in. |
| G-C-3 (F3) | Inbox "Seen by HR" / "Object" skip the `leaves.review` permission that the old table action requires; an objection returns days to the balance. | Medium | **Fixed (2026-10-28).** `LeaveRequestPolicy::review` / `object` require `leaves.review`. Test `test_gc3_*`. |
| G-C-4 (D-02, D-04) | After HR decides on behalf (or any older HR decision), HR's review row says "The manager accepted/rejected". The employee's view is correct. | Medium | **Fixed (2026-10-28).** `LeaveRequestNextStep::decisionSummary()` words HR's review row from the stored basis (manager / on behalf of / no direct manager / older decisions by name), EN + AR. Test `test_gc4_*`. |
| G-C-5 (D-03) | Pending requests that already started are labelled "Starts soon" and counted in "⚠ N start within two days". | Medium | **Fixed (2026-10-28).** `LeaveRequest::startFlag()`: *Starts soon* = today..today+2; an already-started pending request is labelled *Already started* and counted on its own line. Test `ForYouNowTest::test_an_already_started_request_is_not_counted_as_starting_soon`. |
| G-C-6 (F4) | Checklist items of former employees are dropped from the inbox/home but still counted by the Take back / Hand out counters and the pending-delivers card. | Medium | **Fixed (2026-10-28).** Leavers' hand-out / take-back items appear in the inbox, marked *Former employee*, and match the counters. Test `test_gc6_*`. |
| G-C-7 (F5) | Each inbox source is capped at 100 rows, and for leave the cap is applied before the "may I decide" filter: HR-SYR / super-admin can see "100" or miss a decidable request. | Medium | **Fixed (2026-10-28).** `WorkInbox::snapshot()`: leave rows are filtered by the policy before the cap, every source is counted in SQL, and the page says when it lists fewer than it counts. Tests `test_gc7_*`. |
| G-C-8 (F6) | "Team contracts" still follows the technical-manager line; leaves, calendar and inbox use direct reports. | Medium | **Fixed (2026-10-28).** `Contract::scopeVisibleTo` follows direct reports only (badges follow). Tests `ContractAccessByRoleTest`, `BadgeAuthorizationTest`. |
| G-C-9 (F14) | Statistics "soon-ending contracts" has no city scope and disagrees with the home figure. Predates C1–C5. | Medium | **Fixed (2026-10-28).** The Statistics card uses `visibleTo()->inView('ending-soon', 30)`, like the home figure. Test `DashboardCardStatesTest::test_soon_ending_card_is_scoped_to_the_hr_roles_city`. |
| G-C-10 (F7, F8) | Home "off this week" covers today+6 but links to the Sun–Sat week; "who's off" includes pending while the upcoming card is accepted-only. | Low | F8 **accepted** (C5-D Q2). F7 **Fixed (2026-10-28).** "Off this week" and its link share `Calendar::weekOf()`. Test `ForYouNowTest::test_team_off_this_week_is_the_week_its_link_opens`. |
| G-C-11 (F9, F10, D-05) | "For you now" omits checklist rows for employee/manager while the badge counts them; a former employee's pending leave shows 0 in the inbox but 1 in Team leaves; badge stayed 3 vs 2 after an "already handled" refusal until the next refresh. | Low | **Fixed (2026-10-28).** F9: the home shows checklist rows for employee/manager roles; F10: a former employee's pending request is not decidable and is hidden from Team leaves (§5); D-05: every inbox outcome refreshes the badge. Tests `test_gc11_*`. |
| G-C-12 (D-06, D-07, D-08) | Manager's own notice says "your manager …"; the bell keeps counting handled requests; Arabic comma in English text. | Low | **Fixed (2026-10-28).** Next-step text worded for its reader; the bell counts unread minus handled (`NotificationRequestState::unreadCount()`); names joined with a locale separator. Tests `test_gc12_*`. |
| G-C-13 (F11, F12, F13, F15) | Calendar "my reports" from positions not active role; on-behalf overlap warning may list another city's pending leave (names only); HR role with a non-existent city treated as nationwide; never-employed volunteer labelled "(former)". | Low | F12, F13, F15 **Fixed (2026-10-28).** (overlap warning limited to the decider's reach; `Employee::scopeInActiveCities` so a role without a city sees nobody; *(former)* only after a real contract). Tests in `TeamCoverageTest`, `ForYouNowTest`, `GlobalSearchTest`. F11 left (low). |
| G-C-14 (D-09, D-10) | Leave-type list reopens after keyboard selection; Centers page 500 for a manager on SQLite (`09:00` without seconds). | Low | D-09 needs correction; D-10 check on MySQL before acting. |
| G-C-15 | Notes out of date: C5 §5 (upcoming card replaced by C4), C1 (interim inbox count replaced by C3; C1 §6 puts the team calendar in *My team*, the menu has it in *My space*). C3 needs `php artisan migrate` on deploy. | Info | Update notes. |
| G-C-16 | Built assets were older than `resources/` (re-ran `npm run build`); older launch entries point at a removed `php-8.5.11` path. | Info | Environment only. |

**Accepted as delivered:** C1 navigation, C2 bilingual search and quick actions, C5 calendar privacy, the shared count source across badge / inbox / dashboard, and concurrency/stale-role handling.
**Release-blocking for Phase C:** none left from G-C once the §3 matrix is re-run on the corrected SHA; G-C-1 and G-C-2 are fixed.

## 5. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-G-C-01** Findability and task success are reported against X2 without invented improvements. | **Met, with coverage limits** | §2: 6 successful task runs, entry found for every task and C0 T1–T8; no participant data, so no improvement claimed; uncovered combinations listed. |
| **AC-G-C-02** No contradictory actionable counts or private calendar/profile exposure in the defined matrix. | **Not met** | §3: privacy held everywhere and the main surfaces agree, but G-C-1 (0 in every inbox vs. pending in All leaves), G-C-5, G-C-7 and G-C-11 are contradictory actionable counts. |
| **AC-G-C-03** The gate states whether changes are accepted, need correction or are held for policy decisions. | **Met** | §4 dispositions. |

## 6. Handoff

- **C3 owner:** G-C-3, G-C-4, G-C-7 (G-C-1 and G-C-2 are built). **C4/C5 owner:** G-C-5, G-C-6, G-C-10, G-C-11, G-C-13. Then re-run the §3 matrix on the corrected SHA.
- **HR product owner:** G-C-1, G-C-6 and G-C-8 decided on 2026-10-27 (DEC-05 revision 2); nothing left open.
- **D4** may start its design work; it must not build on the current on-behalf rule until G-C-1 is decided. **X1-I** may note the inbox being computed 2–3 times per page (audit F16). **X2-R** inherits §2's uncovered combinations and the need for real participants. **G-R** must cite the corrected SHA, not `aa983edc`.
- **Formal review (QA engineer + technical lead): pending.**

## 7. Re-run on the corrected code (2026-10-28)

Branch `G-C` at **`4b07f3e6`**; full suite **1058/1058**. Fresh synthetic copy `C:/laragon/tmp/hc-hr-gc2`, launch entry "hc-hr G-C re-run (synthetic SQLite copy)", port 8403. Evidence: [evidence/g-c/browser-rerun.md](evidence/g-c/browser-rerun.md). Moderator run, no participants.

**First-run defects:** D-01 to D-08 **fixed** in the browser (D-01: the request of an employee-role manager's report is in that manager's inbox and was decided there; HR-HMS's pending total 10 = 2 HR decisions + 8 the position holder decides). D-09 (leave-type list reopens) and D-10 (`/centers` 500 on SQLite) still present (G-C-14).

**Matrix (AC-G-C-02):** badge = inbox tabs = dashboard = All/Team leaves pending = calendar today = upcoming card for staff, both managers and both HR roles, including the on-behalf and already-started states. Privacy probes (other city, non-reports, employee-role manager, technical-line contracts) all refused with no DB change. Two sessions on one request: one write, "already accepted by …", badge refreshed. Reminder: exactly one per manager and city HR; a second run sends none; the link opens the inbox row.

**New cells covered:** A EN phone, B EN phone, C AR phone, D AR desktop (contract created with the auto-renew box; one seat).

| # | Finding | Severity | Disposition |
|---|---|---|---|
| G-C-17 (N-01) | Employee-role manager-position holders see their decisions in the badge and inbox, but "For you now" on the home shows nothing (`ForYouNow::plan()` picks parts by role name). | Medium | **Fixed (2026-10-28).** The home adds the decisions part for anyone holding a manager position (`ForYouNow::plan`). Test `test_gc17_*`. |
| G-C-18 (N-02) | For a request with no direct manager, HR is the decider, but its reminder says "still awaiting the manager … the manager was reminded", has no decide action and links to All leaves. | Medium | **Fixed (2026-10-28).** HR that may decide now (no direct manager, or all on leave) gets a decide reminder linking to the inbox row (`hr_decider`). Test `test_gc18_*`. |
| G-C-19 (N-03, N-06, N-07, N-09) | HR deciders get the new-request notice in the requester's wording; the on-behalf summary names one of two absent managers; HR is asked to mark its own decisions as seen; masculine Arabic pronoun for a female manager. | Low | N-03 **fixed (2026-10-28)**: HR reads "Waiting for your decision" when it decides, otherwise "… You have been informed" (test `test_n03_*`). N-06, N-07, N-09 need correction. |
| G-C-20 (N-04) | The bell still counts unread "no longer available" notices. | Low | Needs correction. |
| G-C-21 (N-05) | Composer radios announced as "1/5/3"; comboboxes as «اختيار». | Low (a11y) | Needs correction. |
| G-C-22 (N-08) | TAR request 34 has no decider and no reminder audience (synthetic data: no manager account, no HR in the city). | Info (data) | HR data task. |

**Not covered:** A AR desktop, B AR desktop, C EN phone, D EN, D phone, D′; G-C-3 with an HR role lacking `leaves.review`, G-C-6 leavers' items, G-C-7 past 100 rows, G-C-9, G-C-10 in the browser (all covered by tests); HR objection flow; general manager, super-admin; MySQL; screen reader; real phone.

**Result:** AC-G-C-02 is **met** in the defined matrix (no contradictory actionable count or private exposure observed). G-C-17 and G-C-18 are fixed (suite 1060/1060); Phase C acceptance now waits on the formal review. Low items G-C-14 and G-C-19 to G-C-21 stay open.
