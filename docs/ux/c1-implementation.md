# C1 · Menu by personal, team and HR work

Task: C1 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-06) · Date: 2026-10-26 · Branch `hr-ux-by-rafi`
Inputs: C0 [interaction-contract.md](interaction-contract.md) §4, §7, §8 (HR decisions C0-Q1, Q2, Q4); B1 (navigation).
Status: **built and tested; review pending.**

## 1. What changed

| Part | Change |
|---|---|
| **Menu structure** | One definition, [`App\Support\Navigation`](../../app/Support/Navigation.php), read by the sidebar, the breadcrumbs and the tests. Areas: **Work inbox** (top, with a count) · **My space** · **My team** · **HR operations** · **Settings** · **Organization**. An area appears only when it has an item for the active role. |
| **Visibility** | Every item keeps the **same permission check the old sidebar used** (`hasActivePermission`, plus the IT/CT/HR rule for checklist hand-out). The areas only decide *where* an item sits. No route, middleware or policy changed. |
| **Same page, different place** | *All leaves* and *Contracts* sit in **My team** for the `manager` role ("Team leaves", "Team contracts") and in **HR operations** for everyone else. Reference pages (centers, programs, positions, project basics) sit in **HR operations / Settings** for roles that can edit them, and in **Organization** for roles that only read them (C0 §4, "one page, one place"). News sits in HR operations when the role has any, otherwise in Organization. |
| **Work inbox (interim)** | Shown to roles with `leaves.decide` or `leaves.review`. Count = leave requests in `LeaveRequest::manageableBy()` that are pending (roles that decide) or decided and not yet seen by HR (roles that review). Links to *All leaves*. **C3 replaces both the count and the link** with its inbox (C3-D §3, §5). |
| **Active role** | A line under the logo: "Working as: <role>" (`role_label()`, e.g. "HR · ALP"), so the menu's context is visible. Hidden when the sidebar is collapsed. |
| **Breadcrumbs** | The first crumb is now the page's **area for the active role** (`Navigation::areaFor()`, worked out once on the page request). The old group crumbs ("Employee management", "Holidays and leaves", "Program management") were removed from 17 views. |
| **Guided tour** | The dashboard tour's menu steps are now one per area (`nav-inbox`, `nav-my-space`, `nav-my-team`, `nav-hr`, `nav-settings`, `nav-organisation`) with new EN/AR text. An area a role does not see is skipped, as before. |
| **Stable IDs** | Every link carries `data-nav="nav.<area>.<item>"` (C0 §7); areas carry `data-nav-area` and `data-tour="nav.area.<id>"`. Existing `data-tour` hooks kept on their items. |
| **Labels** | New: the area names, *Work inbox*, *Organization*, *Team leaves*, *Team contracts* (EN/AR, `general.nav.*`). **Existing pages keep their current names** (e.g. «اجازاتي», «ممتلكاتي», «مجلداتي»), so the menu, page titles and the user guide still match. The prototype's alternative names (e.g. «عهدتي», «ملفاتي») are left for X2-R to test (C0 §11). |

## 2. Decisions taken in C1 (reversible)

1. **Organization comes last**, not under My space as C0 §4 drew it. Under My space its long list (up to 15 reference links for a manager) pushed *My team*, the manager's daily work, below the fold. C1's objective is that frequent work comes first. Recorded here and in C0 §4.
2. **English spelling "Organization"** (the app's existing spelling, e.g. "Organization Chart"); C0's glossary said "Organisation".
4. **C0-Q4 changed to answer 2 (2026-10-27):** in *Organization*, an `employee` now sees only the org chart, and a `manager` the org chart, centers and programs; positions and project basics left their menus. Since G-B-13 (answer 2) these pages are also refused by URL for those roles (`test_reference_pages_left_out_of_the_menu_are_refused_by_url`; the read permission stays, see g-b-gate.md §9), and `test_each_role_keeps_exactly_the_pages_it_had` holds the new lists.
3. **The current item stays highlighted after the phone drawer opens** (bug fixed on the way). The sidebar re-renders through Livewire's update route, where `routeIs()` no longer knows the page; it now keeps the page's route from the first request (`Sidebar::$currentRoute`). The old sidebar had the same bug.

## 3. Security

- The menu grants nothing and hides nothing that was shown before: `test_each_role_keeps_exactly_the_pages_it_had` compares each role's menu with the old sidebar's page list.
- The inbox count uses the same scope as the leave list (`manageableBy`), never an unscoped count (C3-D §5). It shows a number only, no names.
- `Sidebar::$currentRoute` is client-writable, like any Livewire property; it only decides which link is highlighted.

## 4. Tests

[`NavigationAreasTest`](../../tests/Feature/NavigationAreasTest.php) (7 tests) and one in `LeaveRequestWorkflowTest`:

| AC | Test |
|---|---|
| **AC-C1-01** Visible navigation matches what the active role can open | `test_every_menu_link_opens_for_the_role_that_sees_it`: every link in the menu of employee, manager, general manager, HR-ALP, HR-SYR and super-admin is requested as that role: none returns 403 or 404. `test_switching_the_active_role_changes_the_menu`. |
| **AC-C1-02** Settings and all authorized old functions remain reachable | `test_each_role_keeps_exactly_the_pages_it_had`: the six roles' menus list exactly the old sidebar's pages. `test_areas_and_placement_follow_the_active_role`: Settings for HR-SYR / super-admin (access, activity log), basics in Settings for HR, under Organization for an employee. |
| **AC-C1-03** Collapsed/mobile, breadcrumbs, tours usable EN/AR | `test_breadcrumbs_start_with_the_area_of_the_active_role` (EN: "My team" for a manager, "HR operations" for HR on the same page); `test_the_current_page_stays_highlighted_after_the_drawer_opens`; browser checks §5. |
| Inbox | `test_the_inbox_shows_a_count_only_for_roles_with_a_source`; `test_c1_work_inbox_counts_pending_requests_of_the_managers_team` (1 with a team member's pending request, 0 once it is rejected). |

**Full suite:** 946 tests, 5325 assertions, 0 failures.

**Test fixed on the way:** `ContractCreatePerformanceTest::test_city_options_are_compact_scoped_and_query_bounded` looked for "the first query that mentions employees" and expected it not to be `select *`. That first query used to be the HR user's own profile lookup, so the employee options query was never checked. With C1 the profile is already loaded, the first match became the real options query, and its `NOT EXISTS (select * …)` subquery tripped the check. The test now picks the options query itself and checks its own column list. Its query count went from 14 to 13.

## 5. Browser checks (synthetic copy `hc-hr-gb12`, Arabic)

| Check | Size | Result |
|---|---|---|
| `manager.alp`, Contracts | 1280×800 | "Working as: manager"; Work inbox 1; My space · My team (Team leaves, **Team contracts** highlighted) · Organization last; breadcrumb «فريقي / العقود» |
| Collapsed sidebar | 1280×800 | 80 px wide; area headings hidden; 13 icons; the inbox count sits on its icon |
| Phone drawer, My leaves | 375×667 | Drawer opens; «اجازاتي» highlighted (after the fix); no sideways page scroll; breadcrumb «مساحتي» |
| Dashboard tour | 1280×800 | 13 steps; menu steps: intro, inbox, My space, My team, Organization (HR and Settings skipped for a manager); text shown in Arabic |

**Not checked in the browser:** English. `/en` redirects to https on the `php -S` copy (G-B-12); the English labels are covered by the tests. HR and super-admin menus were checked by the tests, not in the browser.

## 6. Handoff

- **C3** replaces the interim inbox: its route and count go in `Navigation::inbox()`; the menu, tour and breadcrumbs already use the name *Work inbox / صندوق المهام*.
- **C5** adds *Team calendar* to My team (`Navigation`, `$myTeam`).
- **C2** reads the same map for page search (pages of the active role's menu, C0 §6).
- **X2-R** runs the C0 scenario check on the built menu.
