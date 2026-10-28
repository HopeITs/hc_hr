# C5 · Team calendar and coverage warnings

Task: C5 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-12) · Date: 2026-10-27 · Branch `hr-ux-by-rafi`
Inputs:
- C5-D [team-coverage-contract.md](team-coverage-contract.md): DEC-06 and the HR answers of 2026-10-21.
- A3 `LeaveRequestPricing`, used for weekends and holidays.
- C1 menu.
- C3 decision screens.

Status: **built and tested; review pending. No migration, no data change.**

## 1. What people get

| Who | What they see |
|---|---|
| **Everyone** | *My space → تقويم الفريق* (`/team-calendar`): month, week, day and list views. The team filter covers all my colleagues, my department, my center and my manager's team. Phones get the list instead of a month grid. |
| **Colleague level** | Accepted leave only. Sick, maternity, paternity and bereavement show as **«في إجازة»**; other types by name. Never the reason, the status, the times or the request. |
| **Direct manager** | Also *فريقي المباشر* (the default for a manager): real type, pending (marked), hours, substitute, and "away X of Y" per day. |
| **Project manager (grade 3)** | Also the project managers of the same program, and the service departments in the same governorate (visibility only). |
| **HR (city)** | *مدينتي* (default): everyone in its own city at HR level. Other cities only through its own colleague set. |
| **HR-SYR, super-admin, general manager** | *الجميع* with a city filter. The general manager sees at manager level. |
| **Requester (leave form)** | Under the A3 preview: «N زملاء في إجازة في بعض هذه الأيام» (a count, no names, accepted only, on the requester's working days), and «مديرك المباشر في إجازة…» when relevant. Advisory: the request can still be sent. |
| **Deciding manager** | In the inbox row and the *All leaves* decision dialog: «أعضاء آخرون في هذا الفريق في إجازة في هذه الأيام» with names, status and dates. Advisory: Save stays enabled. |

## 2. What was built

| Part | Where | Notes |
|---|---|---|
| One reader | [`TeamCoverage`](../../app/Services/TeamCoverage.php) | <ul><li>`absences()`, `coverage()`, `colleaguesAwayCount()`, `managerAway()`, `teamOverlap()`, `scopesFor()`, `defaultScope()`, `maxLevel()`.</li><li>Read-only. No global Carbon state.</li><li>Weekends and holidays come from `LeaveRequestPricing::weekendFor()` / `holidayDates()`.</li></ul> |
| Rules | `config/team-coverage.php` | <ul><li>Project-manager grade: 3 (T-5).</li><li>Service department codes (T-6).</li><li>Leave types shown as «في إجازة» (Q1).</li><li>`max_days` = 62.</li></ul> |
| Who is visible (§4.1) | `TeamCoverage::members()` / `levelFor()` | <ul><li>Built from the viewer's positions on **active** contracts and the **active** role.</li><li>Only active employees with a running contract.</li><li>The highest level wins.</li><li>A scope the role may not use returns the same empty result as an empty team.</li></ul> |
| Overlap (§4.2) | `overlapping()` | `start_at < (to+1) 00:00 AND end_at >= from 00:00`, which is sargable. Rejected never counts. |
| Projection (§4.4) | `project()` | <ul><li>Plain arrays.</li><li>At colleague level the row key is an opaque hash and there is no request id.</li><li>Never: reason, reject reason, HR note, balances, pay, `dob`.</li></ul> |
| Page | `App\Livewire\Team\Calendar`, `livewire/team/calendar.blade.php`, `partials/chip` | <ul><li>Only scalars are public. Absences are computed in `render()`, so none sit in the component state.</li><li>URL values (`view`, `date`, `scope`, `status`, `city`) are re-checked against the role on every render.</li><li>Status is shown by text and shape: tick with a solid border, clock with a dashed border, half circle for part of the day.</li></ul> |
| Requester hint | `MyLeaveRequestModule::teamHint()`, `partials/impact-summary` | Same service, same window as the A3 preview. |
| Manager warning | `Inbox\Index::overlapFor()`, `AllLeaveRequestModule::decisionOverlap()`, `team/partials/overlap-warning` | `teamOverlap()`: the requester's direct-manager team, accepted and pending, on the requester's working days. |
| Menu | `Navigation` (*My space*), route `team.calendar` (`leaves.view-own`) | `move`, `goToday` and `openDay` are listed in `livewire_exempt`. They only change the period shown. |

## 3. Tests (`tests/Feature/TeamCoverageTest.php`, 19 tests; §7 personas as fixtures)

| AC | Tests |
|---|---|
| **AC-C5-01** Calendar and leave-form warnings agree | <ul><li>`test_e5_the_requester_hint_agrees_with_the_calendar`: same set, same working days, and a holiday-only day counts 0.</li><li>`test_the_leave_form_shows_the_colleague_count_without_names`: the form's number equals the service's.</li><li>`test_e4_…`, `test_the_decision_screens_show_the_overlap_warning`.</li></ul> |
| **AC-C5-02** Visibility and status rules match DEC-06 in EN and AR | <ul><li>`test_e1_…`: «On leave», no reason, status or id.</li><li>`test_e2_…`: real type, accepted, holiday not counted.</li><li>`test_e3_…`: pending hidden from colleagues, marked for the manager.</li><li>`test_e6_e7_…`: an out-of-set absence leaves `coverage()` identical.</li><li>`test_e8_e9_…`, `test_e10_…` (rejected never), `test_e11_…`, `test_e12_e13_e14_the_colleague_set`.</li><li>`test_a_scope_the_role_may_not_use_is_empty`, `test_former_staff_are_not_listed`.</li><li>`test_the_calendar_page_…_in_both_languages`.</li></ul> |
| **AC-C5-03** Keyboard and phone navigation without private reasons | <ul><li>`test_the_manager_view_marks_pending_and_navigates`: move, openDay, goToday, status filter.</li><li>`test_a_scope_from_the_url_is_checked_against_the_role`.</li><li>The page test asserts the list markup and that only scalar state is kept.</li></ul> |
| Step 2: bounded queries | <ul><li>`test_a_range_over_the_bound_is_refused` (> 62 days).</li><li>`test_query_count_does_not_grow_with_the_team`: 50 members in one center cost at most 6 more queries than 1.</li></ul> |

Also updated:
- `NavigationAreasTest`: the team calendar is in every role's *My space*.

**Full suite:** 997 tests, 5608 assertions, 0 failures (final code, 2026-10-27). An earlier run failed once on a flawed assertion of mine (a random hash can contain the request id's digit); it now checks the key's form.

## 4. Browser checks (synthetic copy `hc-hr-gb12`, Arabic)

| Who | Check | Result |
|---|---|---|
| `manager.alp` | Calendar, narrow pane | Defaults to «فريقي المباشر»; list per day with «غائب 1 من 28», type and status; weekend days marked. |
| `manager.alp` | 1280×900 | Month grid, weeks Sunday to Saturday; today highlighted; weekend cells shaded and labelled; chips in each cell. |
| `hr.alp` | List, November | Defaults to «مدينتي»; a pending request shows «قيد المراجعة» in words with a dashed chip; counts «غائب 0 من 56 · 1 قيد المراجعة». |
| `hr.alp` | Phone 375×812 | Grid hidden, list shown; no sideways scroll; no button or select under 44 px. |

**Not checked in the browser:**
- English, because `/en` does not work on the `php -S` copy (G-B-12). The EN page is covered by the tests.
- The requester hint and the manager warning with synthetic data. They are covered by the Livewire tests.

## 5. Limits and handoff

- **Holidays:** this is the A3 model: national (SYR), the center's city, and the program in every governorate. The per-project holidays and the birthday holiday of contract §6.1 need a schema change owned by the technical lead (T-1). Until then they do not exist, and the calendar cannot show them. Holiday names are not shown, because `holidays.name` is in one language only.
- **Holiday and weekend shading on the grid** follow the viewer's own contract. Each member's own working days are still used for counts.
- **Dashboard card (C4):** `UpcomingLeavesCard` is unchanged. It still uses `scopeUpcomingVisibleTo`, which keeps the technical manager and shows rejected leave on the HR card (T-3, T-4). C4 should replace it with `TeamCoverage::absences(range: today…)`, accepted only.
- **No link from a manager-level entry to the request yet**; the inbox and *All leaves* remain the way in.
- **The leave-balance pages** still use `tech_manager_id` (carried over from C3).
- **The technical lead's review of contract §4 and §6** is still pending.
