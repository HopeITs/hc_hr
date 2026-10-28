# C4 · "For you now" on the home page

Task: C4 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-10) · Date: 2026-10-27 · Branch `hr-ux-by-rafi`
Inputs: C0 [interaction-contract.md](interaction-contract.md) §5 (C0-Q5); C3 `WorkInbox`; C5 `TeamCoverage`; B3 card states; B4 contract views; B5 employee views.
Status: **built and tested; review pending. No migration, no data change.**

## 1. What people see

At the top of the home page there is now one ordered block, **«لك الآن» / For you now**, for the active role. The existing cards are kept below it, unchanged, which is C0-Q5. Parts follow the C0 §5 order:

| Role | Parts, in order |
|---|---|
| Employee | My leave (remaining balance, reserved amount, next holiday, **طلب إجازة**) · My open requests with their next step · My items to receive or hand back |
| Manager | **بانتظار قراري** (count, how many start within two days, first 3 rows) · Who in my team is off (today, later this week) · My leave |
| General manager | Needs my decision · Key figures (active employees, running contracts → Statistics) · Contracts ending in 30 days |
| HR (city and national) | **صندوق المهام** (count by kind: decisions, to review, items; first 3 rows) · Contracts ending in 30 days · Volunteers awaiting a contract / incomplete records |
| super-admin | Work inbox · Settings shortcuts (access management, activity log) |

- A part with nothing to show is left out.
- With nothing at all, one line appears: «لا شيء بانتظارك الآن.»
- Each part links to the page it summarises, already filtered. Inbox rows open their own row (`?open=`), and the decision part opens `?show=decide`.
- The other links open `?view=ending-soon`, `?view=awaiting`, `?view=incomplete`, the week of the team calendar, and `?new=1` for a new request.

**Upcoming leaves card:** now read from `TeamCoverage` (the C5 handoff). It uses the role's default team and shows **accepted leave only for every role** (C5-D Q2, DEC-05 rev.). The technical-manager line and the rejected and pending rows on the HR card are gone (T-3, T-4). It covers today plus 61 days, and «عرض الكل» opens the team calendar.

## 2. How (AC mapping)

| Part | Where | Rule |
|---|---|---|
| Section | [`Dashboard\ForYouNow`](../../app/Livewire/Dashboard/ForYouNow.php), `livewire/dashboard/for-you-now.blade.php` | <ul><li>No public properties.</li><li>Everything is rebuilt from the **active** role in `render()`, so a role switch shows nothing of the previous role (AC-C4-02).</li><li>Each part is loaded in its own `try`: a failed part says so with *Try again*, and the others still show.</li></ul> |
| Same counts as the pages (AC-C4-03) | Inbox: `WorkInbox::rows()` (the inbox page's source). Ending: `Contract::visibleTo()->inView('ending-soon', 30)`. Waiting: the employees table's city scope + `awaitingContract()` / `drafts()`. Team off: `TeamCoverage::absences(…, 'reports', …)`. | `Contract::scopeInView()` is new. The contracts table's presets were **moved** into it, so the table and the home figure run the same query. |
| No pay | — | No salary, no pay tier, and no balance beyond the viewer's own. `LeaveBalance::remaining` and `formatDuration()` return markup for the old card, so here they are shown as plain text. |

## 3. Tests

| AC | Tests |
|---|---|
| **AC-C4-01** C0 priorities and next action per role | `ForYouNowTest::test_each_role_sees_its_parts_in_the_c0_order`: manager `[decisions, team-off, balance]`, employee `[balance, my-requests]`, HR `[inbox, contracts-ending, employees-waiting]`; the next-action links. `test_empty_parts_collapse`. |
| **AC-C4-02** Role switch without leaking | `test_switching_role_changes_parts_without_leaking`: employee → HR → employee; HR figures gone; component state empty. |
| **AC-C4-03** Linked, filtered, nothing inaccessible counted | `test_counts_match_the_pages_they_open_and_skip_inaccessible_work`: decide count equals the inbox page's; the Damascus contract and the Damascus volunteer are not counted for HR-ALP; filtered links. |
| Independent loading, no pay | `test_a_failing_part_does_not_hide_the_others`, `test_no_pay_is_shown`. |
| Card | `UpcomingLeavesCardTest` updated for accepted-only and the 62-day bound. The changed expectations cite C5-D Q2 and T-3. `DashboardCardStatesTest` fixture now uses an accepted leave for the same reason. |

**Full suite:** 1004 tests, 5638 assertions, 0 failures.

**Found on the way, by the browser check:** the balance showed `<strong …>18</strong>` as text, because the existing accessor returns markup. The reserved amount had the same problem. Both are now stripped, and the test checks the escaped form, which was proven to fail before the fix.

## 4. Browser checks (synthetic copy `hc-hr-gb12`, Arabic)

| Who | Check | Result |
|---|---|---|
| `hr.alp` | Home | «لك الآن» above the old cards. «صندوق المهام · 12 عنصرًا بانتظارك · قرارات الإجازة 5 · للمراجعة 6 · العهدة 1 · ⚠ 3 تبدأ خلال يومين», first 3 rows, then «عقود تنتهي خلال 30 يومًا · 8 عقود». |
| `hr.alp` | Contracts → *ending soon* | 8 rows, the same as the home figure. |
| `manager.alp` | Home, phone 375×812 | «إجازتي · المتبقي من سنوية: 18 يومًا · طلب إجازة · اجازاتي». No decisions waiting and no one off this week, so those parts are left out. The block sits at the top, there is no sideways scroll, and every link is at least 44 px. |
| `manager.alp` | «طلب إجازة» | Opens My leaves with the new-request dialog open. |

**Not checked in the browser:** English (G-B-12; covered by the tests), the employee persona, and the general manager.

## 5. Limits and handoff

- **Personalisation** (choosing or reordering parts) is deferred, as the task allows.
- **"Documents"** (task step 1) is reached from *My space → My files*; the section has no separate documents part. Upcoming holidays show as the next one within 60 days, from the A3 holiday reader (see C5 §5 for its limits).
- **The old cards below are unchanged** (C0-Q5), including news and notes, which now sit below the operational block. Removing or merging old cards is a later decision.
- **Consumers:** G-C (task checks per role), D2 (mobile self-service).
