# HR Process — Test Report

Environment: local `https://hc-hr.test` (Laragon, DB `hr_db`), branch `hr-ux-by-rafi`, 2026-11-18. Browser: the Claude desktop browser pane with emulated viewports.
Plan: [HR_TEST_PLAN.md](HR_TEST_PLAN.md). The issue IDs below continue across page groups.

Evidence notes: the browser pane's screenshots aren't saved as files, so each one is described in words. Measurements come from the live DOM (`clientHeight/scrollHeight`, `getBoundingClientRect`).

---

## Group 1 — Login, app shell, dashboard (P01–P07)

### 1. Page summary
| Page | Route | Files |
|---|---|---|
| Login | `/{locale}/login` | `app/Livewire/Auth/Login.php`, `resources/views/livewire/auth/login.blade.php` |
| App shell | every page | `app/Support/Navigation.php`, `Livewire/Layout/*`, `Livewire/Auth/RoleSwitcher.php`, `Livewire/Notifications/*` |
| Dashboard | `/{locale}` and `/dashboard/window/{tile}` | `Livewire/Dashboard/*`, `app/Support/Dashboard/DashboardLayout.php`, `resources/css/dashboard.css` |

### 2. Role matrix

Access was checked for every route by direct URL (`fetch`), not only through the menu.

| Role (account) | Access | Can see (menu) | Can manage | Blocked (server-side, by direct URL) | Notes |
|---|---|---|---|---|---|
| Guest | Login, `/ID/{card}`, `/careers/{token}` only | — | — | Every app route redirects to the login page. `/register` returns 404 | The public ID and careers pages return 404 for invalid tokens |
| Employee E1 (celine.khoury, Latakia) | Dashboard, My leaves, Team calendar, My timesheet, My items, Mail lists, Folders, Org chart, My profile, settings | Colleagues' profiles **at directory tier only** (role, manager, work contact). Includes other cities, which matches the access policy in `DirectoryAccess.php` | Own leave (create sheet: only "Leave request") | 403: employees, contracts (incl. own), all leaves, balances, holidays, attendance upload, checklist admin, positions, centers, programs, basic settings, news, badges, templates, statistics, activity log, access management, feedback, all recruitment. Dashboard window `stats-salary` returns 404 | Former employees return the same 404 as an unknown username (no enumeration) ✔ |
| Manager MG1 (adonis.mowas) | My space + **My team**: Team leaves, Team timesheets, Team contracts. Recruitment: requests, applicants, interviews, offers. Org: chart, centers, programs, news | Contracts: **own direct report's (`/contracts/98`) 200; another manager's report (`/contracts/32`) 403; other city 403** ✔ | Leave decisions for reports (dashboard: "5 leaves started with no decision") | 403: employees, contract edit/export, balances, holidays, positions, basic settings, badges, statistics, recruitment approvals (not an eligible approver, by design) and campaigns (see HR-009) | |
| HR city HRC (lina.sayegh, HR-LAT) | Full HR menu, no activity log / access management | Latakia only: Aleppo employee edit, contract view/edit/amend/PDF all **403** ✔ | Employees, contracts, leaves, balances, attendance upload, templates, badges, statistics, basic settings, recruitment | 403: activity log, access management, feedback, indicator rules, `/holidays` (route) | Lina has **no active contract**, so her own dashboard has no leave tile |
| HR-SYR HRS (lana.abdo) | Everything incl. access management, activity log, matching rules, pending delivers/returns | All cities | All | 403: feedback (IT-only rule) | Administrator role, works as designed |
| General manager GM (fredy.youssef) | Employees, contracts, all leaves, attendance, badges (read-only), statistics, news, recruitment incl. approvals and campaigns, deliverables, org pages, basic settings | All cities | Employees create/edit, contracts create/edit, programs create | 403: contract **PDF export/preview** (see HR-008), amend, balances, holidays, attendance upload, positions/centers create, templates, activity log, access management. Dashboard window `stats-salary` returns 404 (pay is HR-only, by design) | |

**Role switcher** (lana HR-SYR⇄HR-ALP, lina HR-LAT⇄HR-TAR):
- Switching saves `is_active` on the role row in the DB, so it applies to all of that user's sessions.
- **Stale-tab test:** I opened the employee list as HR-LAT (35 rows), switched to HR-TAR in another tab, then refreshed the old tab's Livewire table. It returned 6 Tartus rows, and a Latakia edit URL now gave 403. Scope follows the new role on the very next request ✔
- Forging `switchTo(1)` (super-admin, a role lina doesn't hold) is refused with 403 and the role is unchanged ✔
- Lina was switched back to HR-LAT afterwards (see Test Data Log).

### 3. Modal inventory
| Modal / sheet | Who sees it | Contents / behaviour |
|---|---|---|
| "Start something new" bottom sheet (+ button) | all roles | Employee sees only "Leave request". Opens and closes correctly |
| Mobile menu drawer | all roles | Items match the role (above). The backdrop does not cover the bottom nav (HR-005) |
| Dashboard "Add a tile" sheet | all roles | Lists only the tiles the role is allowed. The GM's list has no salary tile ✔ (`DashboardLayout::allowed()`). Shortcut list repeats "Contracts" (HR-007) |
| Dashboard edit mode (Reset / Add / Done, ready-made layouts, phone bar picker) | all roles | Works. Two solid red buttons side by side (HR-012) |
| Notifications dropdown | all roles | Links have no locale prefix (HR-010) |
| Livewire error overlay | — | Appears only when a forged call is refused (a standard Livewire behaviour, not a user path) |

### 4. Issues

**HR-001 — Medium — UI/Mobile — Weather tile is clipped on phones**
- Page / role / viewport: Dashboard, all roles, 390px and 360px.
- Steps: open the dashboard on a phone.
- Expected: the tile shows the weather, the temperature and the city. Actual: at 390px only "Partly cloudy" shows, and the temperature and city are cut off. The `.hc-glance-weather` box is 53px tall for 131–137px of content. At 360px the label is cut to "P." next to "20°". At 768px and up it's fine.
- Evidence: DOM `hc-glance-tile hc-glance-weather 53/134`. Screenshots at 390 and 360.
- Suggested fix: let the weather tile grow to its content on the phone layout, or drop the secondary line on purpose there (not by clipping).

**HR-002 — Low — UI/Mobile — "My Leaves" card cuts its last line**
- Dashboard, all roles with a contract, 390px. The `.hc-leaves` card is 173px for 190–203px of content, so the "TOTAL" line is partly hidden. With longer values ("4 Days & 5 Hours") it gets worse.
- Suggested fix: give the card a content-based minimum height on phones, or use `min-h` instead of a fixed row height.

**HR-003 — Low — Mobile/RTL — Bottom navigation labels don't fit**
- All roles, 360px and 390px, EN and AR.
- At 360px "Dashboard" needs 67px in a 64px slot, so it shows as "Dashboarc". "Team calendar" wraps to two lines at every phone size (that item is 68px tall, the others 56px). In Arabic "ملفي الشخصي" also wraps.
- Suggested fix: shorter labels on phones ("Calendar", "Home"), `text-caption` with a 2-line clamp applied to all items equally, or a fixed label height so the row lines up.

**HR-004 — Low — Mobile — About 100px of empty space above the first card**
- Dashboard, all roles, phones. With nothing minimised, the dock strip is still drawn (`min-height: 36px` plus `margin-bottom: 42px` plus a border, `resources/css/dashboard.css:2034–2051`). This is intentional ("so the grid never jumps"), but on a phone it costs about an eighth of the first screen and looks like a loading gap.
- Suggested fix: collapse the dock on phones outside edit mode when it's empty.

**HR-005 — Low — UI — The mobile menu backdrop doesn't cover the bottom nav**
- All roles, 390px. With the menu drawer open, the bottom nav ("Team calendar", "My Profile") stays sharp above the blurred backdrop.
- Suggested fix: raise the drawer/backdrop z-index above the bottom bar, or hide the bar while the drawer is open.

**HR-006 — Low — UI/Mobile/RTL — "Needs you" card scrolls inside the page and cuts its row**
- Manager and GM dashboards, 390px. The card has its own scrollbar inside the scrolling page, and the last line of the first item ("Annual · 4 Nov – 6 Nov · Latakia") is cut. In Arabic, the row title "طلب إجازة بانتظار…" runs under the "بدأ دون قرار" tag.
- Suggested fix: on phones show one item at a time (the pager already exists) with auto height. In RTL, reserve space for the tag.

**HR-007 — Low — Consistency — Two different pages are both called "Contracts" in the menu**
- HR-LAT, HR-SYR and GM menus: "Contracts" (the list, under HR operations) and "Contracts" (the statistics page in the Statistics group, which sits next to "Employee statistics"). The dashboard "Add a tile" shortcuts list both as "Contracts" with no group.
- Suggested fix: rename the statistics one "Contract statistics" (the route is already `statistics.contract`).

**HR-008 — Low — Permissions (clarity) — The GM holds `contracts.export` but can never use it**
- `config/access-control.php:91` gives the general manager `contracts.export`. `ContractPolicy::export` (`app/Policies/ContractPolicy.php:57-62`) also requires `canViewSalary()`, which is HR-only (`app/Models/User.php:273-280`). So `/contracts/5/export` and `/export-preview` return 403 for the GM.
- Not a leak: pay stays hidden, which is correct. But the permission matrix in Access Management claims something the GM can't do. Whether the GM is shown an export button that then fails will be checked in the Contracts group.
- Suggested fix: remove `contracts.export` from the GM baseline, or note in Access Management that export also needs pay visibility.

**HR-009 — Low — Permissions (drift) — The manager role in the DB is missing two baseline permissions**
- The config baseline for `manager` includes `recruitment-vacancies.view` and `recruitment-campaigns.view` (`config/access-control.php:136`). The `manager` role in `hr_db` doesn't have them (33 permissions). As a result managers get no Campaigns menu item and a 403 on `/recruitment/campaigns`.
- Suggested fix: decide which side is intended, then either sync the role (a migration like `2026_09_16_000001_seed_recruitment_permissions.php`) or remove the two lines from the baseline.

**HR-010 — Low — Other — Notification links have no locale prefix**
- Notifications dropdown, Employee (also seen for others). Links such as `/employees/mirelle.issa/show` work only through an extra locale redirect to `/en/...`.
- Suggested fix: build notification URLs with `LaravelLocalization::localizeUrl()` / `route()` when rendering, rather than storing bare paths.

**HR-011 — Low — Validation — Print/export URLs without `?month` silently bounce to the previous page**
- `/attendance/print` and `/attendance/export/xlsx` with no `month` parameter, any role. `PrintSelection::fromRequest()` calls `$request->validate()` (`app/Services/Attendance/PrintSelection.php:28`), so a GET page redirects "back". In my session "back" was a stale URL, and I landed on a 404. This is only reachable through a hand-edited or bookmarked URL.
- Suggested fix: default to the current month, or redirect to `attendance.timesheets` with a message.

**HR-012 — Low — UI (brand) — Two primary red buttons side by side in dashboard edit mode**
- Dashboard, all roles, edit mode: "Add" and "Done" are both solid red. The brand rule allows one primary action per view.
- Suggested fix: make "Done" primary and "Add" outline.

### Fix status (2026-11-18)
| ID | Status | Change | Verified |
|---|---|---|---|
| HR-001 | **Fixed** | `resources/css/dashboard.css`: narrow glance = two equal rows (weather across, condition + city on one side and temperature on the other; clock, date and time below). Narrow + short = weather stacked (condition up to 2 lines, then temperature) | 390px: "Overcast · Latakia · 21°" all visible. 360px: "Overcast" + "21°". AR mirrored ✔ |
| HR-002 | **Fixed** | `my-leaves-card.blade.php`: type label under the number, columns side by side with lines wrapping in place, `leading-snug`. The "Total" line is hidden on tiles under 200px (it's the "/7" already shown) | 390px 173/173, 360px 157/157, AR 173/173 ✔ |
| HR-003 | **Fixed** | `bottom-nav.blade.php`: icons aligned from the top, `px-0.5`, 11px labels under 380px | 360px "Dashboard" 61/61. All icons at the same top in EN and AR ✔ |
| HR-004 | **Fixed** | Empty dock folds away on phones (`.hc-dock-empty`) unless editing or something is minimised. Desktop unchanged | Strip gone at 390/360 ✔ |
| HR-005 | **Fixed** | `sidebar.blade.php`: backdrop `z-[35]` (above bottom bar z-30, below drawer z-40) | Element at the bottom-nav position with the menu open = backdrop ✔ |
| HR-010 | **Fixed** | `HandlesNotificationActions::withLocale()`: a stored path without a locale gets the viewer's locale, unless it already has one or matches a route outside the locale group (`/ID`, `/careers`). External hosts still refused | `/employees/x/show` and the absolute URL → `/en/employees/x/show`; `/ID/…`, `/careers/…`, `/en/…` unchanged; `evil.example` → null ✔ |
| HR-011 | **Fixed** | `PrintSelection::fromRequest()`: no `month` → the Timesheets page's default month (10 days ago) | `/attendance/print` 200 (12 sheets), `/export/xlsx` → `timesheets-2026-11.xlsx`. New test `test_a_print_link_without_a_month_opens_the_pages_default_month` ✔ |

| HR-006 | **Fixed** | `dashboard.css` (`@container tile (max-width: 519.98px)`), `decisions-card.blade.php`: on narrow tiles the card fits instead of scrolling inside. The summary line (it repeats the count beside the title) is read out but not shown. "View all" keeps its 44px target without adding header height. The action label sits in its own span, ellipsised, on one line with the tag, and its small icon is hidden. Wide tiles unchanged | 390px EN and AR: `.hc-decide` 265/265 (was 265/366), no inner scroll. EN "Leave request to decide" in full. AR "طلب إجازة بانتظار قرارك" in full, no overlap with the tag ✔ |
| HR-012 | **Fixed** | `dashboard/index.blade.php`: edit-mode "Add" uses `icon="layout"`. `<x-button>` turns `icon="plus"` into the red add-square whatever the variant, which caused two red buttons | Reset = quiet, Add = outline (white / ink), Done = the only primary ✔ |

Tests: notifications, bottom nav, dashboard, attendance (315 tests) pass except `BottomNavTest::test_each_role_gets_the_destinations_its_permissions_open`, which **fails without these changes too** (expects no "mail lists" shortcut; checked with `git stash`).

**New finding while fixing HR-010:**
**HR-047 — Low — Logic — The welcome notification has no name in it.** "Welcome !" / "Let's welcome  to the team…": `App\Notifications\NewEmployeeWelcome` uses `$emp->name_en` / `$emp->name_ar`, which don't exist on `Employee` (it has `first_name_en`/`last_name_en`, and `name` resolves to the current locale). Seen in stored notifications for mirelle.issa, issa.george and carla.hanna. Suggested fix: build the EN and AR strings from `first_name_*` + `last_name_*`. **Not changed** (not in the requested list).

### 5. What works well (leave alone)
- **Route protection is consistent:** every page hidden from a role's menu also refused its direct URL (403 or 404) for that role. Guest access redirects to login everywhere.
- **City scoping for HR-LAT:** checked on employee edit and on contract view, edit, amend and PDF preview.
- **Manager scope:** limited to direct reports only, including between two managers in the same city.
- **Role switcher:** server-side and immediate, safe against stale tabs, and refuses roles the user doesn't hold.
- **Directory tier:** colleagues get directory data only. Former employees and unknown usernames return identical 404s.
- **Login:** a generic "Please check your credentials" error, so it doesn't reveal which accounts exist, plus browser-level required fields and a password show/hide button.
- **Salary:** the tile is offered only to HR roles, and `/dashboard/window/stats-salary` returns 404 for the GM and employees.
- **RTL and dark mode:** RTL mirrors the shell and dashboard correctly. Dark mode inverts cleanly with the red unchanged. I didn't find any horizontal scroll at 1440, 1024, 768, 390 or 360px.
- **Performance:** dashboard HTML in about 0.5s, Livewire tile updates 70–120ms.

### Environment notes (not app issues)
- The console shows "An unknown error occurred when fetching the script" when registering the service worker. `/sw.js` serves 200 `application/javascript`. The browser pane most likely refuses to register a worker over Laragon's self-signed HTTPS. Re-check on staging with a real certificate.
- The 403 and 404 entries in the console come from my own direct-URL probes.

---

## Group 2 — Employee pages (P08–P13)

### 1. Page summary
| Page | Route | Files |
|---|---|---|
| Employees list + drawer | `/employees` | `Livewire/Employee/EmployeeModule, EmployeeTable, EmployeeDrawer`, `views/livewire/employee/index.blade.php`, `drawer.blade.php` |
| Create employee (10 sections, autosaved draft) | `/employees/create` | `Livewire/Employee/EmployeeCreate`, `Forms/Employee/PersonalInfoForm` |
| Edit employee | `/employees/{id}/edit` | `Livewire/Employee/EmployeeEdit`, `Forms/Employee/*` |
| Profile (own / colleague) | `/my-profile`, `/employees/{username}/show` | `Livewire/Employee/EmployeeProfile`, `app/Support/DirectoryAccess.php`, `EmployeePolicy` |
| Org chart + person drawer | `/org-chart` | `Livewire/Position/OrgChart, ProfileDrawer, Directory` |
| Public ID card | `/ID/{card}` | `Livewire/EmployeeCards/ShowDigitalId` |

### 2. Role matrix
| Role | Access | Can see | Can manage | Blocked (server-side) | Notes |
|---|---|---|---|---|---|
| Employee (celine) | Own profile, colleagues' profiles, org chart | Own profile: career history (no pay figure), education, experience, personal and work contact, skills, courses, family, digital ID (copy link, report lost). Colleague: **directory tier only** (Employment overview + Work contact) | Self-edit: personal contact, skills; add education / experience / course / family member | Forged `openSection` / `saveSection` on a colleague's profile return **404** (`canEditSection`) ✔. `/employees`, `/employees/create`, `/employees/{id}/edit` return 403 | |
| Manager (adonis) | Same as employee + reports' profiles | **Direct report: records tier** (career history *without pay*, education, experience, skills, courses). **Non-report: directory tier** ✔ | — | Employees list, create and edit return 403 | |
| HR city (lina, HR-LAT) | List (35 Latakia employees), create, edit, full profiles | Everything incl. personal info, contract details, digital-ID controls (issue plastic card, disable, copy link) | Create, edit, **Deactivate, Delete** on every row | Edit of an Aleppo employee returns 403. Forged drawer `open(24)` (Aleppo) returns **404** ✔. Forged delete/toggle re-check policy + city (`scopedEmployee`) ✔ | **But see HR-014 (cross-city delete of family records)** |
| HR-SYR, GM | All cities (verified in group 1 by URL) | Same as HR | GM: create and edit employees | — | The GM has `employees.update`, so HR-014 applies to the GM too |

### 3. Modal inventory
| Modal | Who | Behaviour |
|---|---|---|
| Employee drawer (row click) | HR, GM | Name, position, city, years of service, manager, contact, "View full profile". Scoped server-side ✔ |
| Delete confirmation | HR | Generic "Delete Item — permanently delete the item?" (HR-013) |
| Deactivate confirmation | HR | Reversible toggle; drafts and awaiting-contract employees refused (403) ✔ |
| Profile "Edit Personal Contact" (sheet on mobile) | owner, HR | Clear inline errors (phone must be 10 digits starting 09, valid email). The **"Discard your changes?" guard** appears on Cancel ✔ |
| Org-chart person drawer | everyone | Directory data + direct reports + "Email the team". Shows only one position (HR-015) |

### 4. Issues

**HR-013 — Critical — Logic / Data loss — Deleting an employee is permanent and silently wipes their whole record and login account**
- Page / role / viewport: Employees list, HR (any city in scope), HR-SYR. Every row of every *active* employee has a **Delete** action.
- What a delete does: `EmployeeTable::delete()` calls `$employee->delete()`. `Employee` has **no SoftDeletes**, and the database cascades `ON DELETE CASCADE` to: `contracts` (and with them leave balances, leave requests, contract amendments, encrypted pay), `attendance_*`, `badges`, `checklists`, `educations`, `experiences`, `courses`, `relatives`, `employee_items`, `employee_notes`, onboarding/offboarding/renewal journeys and **`users` (the login account)**. Only `recruitment_hiring_conversions` restricts it.
- The confirmation says only "Delete Item — Are you sure you want to permanently delete the item?", without the person's name or what is lost.
- HR-LAT can do this to anyone in Latakia, including the super-admin's own employee record (Rafi Louliki is in HR-LAT's list with Delete), which would also delete that admin's user account.
- It contradicts CLAUDE.md "never delete an amendment in effect" (the cascade removes amendments) and the leave/attendance history the rest of the system relies on.
- **Not executed** (real data). Evidence: `app/Livewire/Employee/EmployeeTable.php:84-91`, the FK list from `information_schema` (in the session notes), the dialog text seen as HR-LAT at 1440px.
- Suggested fix: remove hard delete for employees who ever had a contract (offer Deactivate/offboarding only). Allow delete only for drafts with no contract, or add SoftDeletes. Name the person and list the consequences in the dialog. Restrict delete to `HR-SYR` / administrators.

**HR-014 — High — Permissions (IDOR) — A family-member (relative) record of any employee in any city can be deleted by ID**
- Page / role: Employee edit, any role with `employees.update` on *one* employee (city HR, GM, HR-SYR).
- Steps (done with my own test data): as HR-LAT, open `/en/employees/405/edit` (Latakia). In the console: `$wire.addRelative()`, then `$wire.removeRelative(<that index>, 1)`, where relative #1 belonged to **employee 24 in Aleppo**.
- Expected: refused (record not on this employee). Actual: **relative #1 deleted** (verified in the DB).
- Cause: `app/Livewire/Forms/Employee/RelativeForm.php:64` uses `Relative::find($id)?->delete()`. The sibling forms scope correctly: `$this->employee->educations()->find($id)` (EducationForm:87), `courses()->findOrFail` (CourseForm:103), `experiences()->find` (ExperienceForm:87), `media()->find` (AttachmentForm:58).
- Suggested fix: `$this->employee->relatives()->find($id)?->delete()`, plus a test that tries a foreign ID.

**HR-015 — Medium — Logic — People holding two positions are shown with only one, inconsistently**
- Org chart, all roles. Example: Rama Daaboul has two active contracts (Second Deputy CEO, position 107; Program Manager, position 103). Her chart card says "Second Deputy Chief Executive Officer", while her drawer says "**Program Manager**" and lists only the Program Manager position's 5 direct reports. The deputy-CEO reports are missing.
- Cause: `ProfileDrawer` takes the latest active contract only (`app/Livewire/Position/ProfileDrawer.php:89-95`).
- Suggested fix: when an employee has more than one contract in force, list every current position (and the reports of each) in the drawer, and show which one the chart node represents.

**HR-016 — Medium — Validation copy — Create-employee errors use raw field names and one wrong message**
- Create employee, HR-LAT, any viewport, empty "Save":
  - Messages name raw attributes: "The first name ar field is required.", "The father name en field is required.", "The mother name ar…".
  - "The City Name field is required." The field's label is "Current Place of Residence".
  - **"The nationality must be an integer."** shows for an *optional* empty Nationality field.
  - "The Username field is required." refers to a field on a later section of the 10-section form; it appears only in the summary.
  - Labels are inconsistently cased ("First name (AR)" vs "Last Name (AR)").
- Suggested fix: add `attributes()` translations (EN/AR) in `PersonalInfoForm`, make nationality `nullable|integer`, and link each summary error to its section.

**HR-017 — Low — UX — Error summary on Create employee sits below the Save buttons and focus doesn't move**
- Phone 390px: Save / Save draft are at y≈1463 and the summary at y≈1558. After the failed save the page stays at the top, with focus on `<body>`, so nothing tells HR the save failed until they scroll.
- Suggested fix: scroll to and focus the summary (or the first invalid field) after a failed save.

**HR-018 — Low — Mobile / A11y — Org chart: toolbar fills the first screen and the text is unreadable**
- Org chart, employee, 390px: the header, 4 stat tiles and 3 rows of controls take the entire first screen, and no chart is visible until you scroll. The search placeholder is cut ("Search name or positi"). Job titles on the cards render at **8.8px** at 100% zoom.
- Suggested fix: collapse the stats and controls behind one "View options" button on phones, and use a 12px minimum for the card text (scale the card, not the type).

**HR-019 — Low — Security (by design, for review) — Public card URLs are guessable**
- `/ID/{city}{program}{employee id}-{index}`, e.g. `/ID/004002405-0`. Mitigations are good: 30 requests/minute, `noindex`, identical 404 for invalid cards, and only name, position, center and city are shown. Still, a scripted walk over city × program × sequential employee ID would list all active staff with a card. It's documented as deliberate (access-policy A2D-Q6).
- Suggested fix (optional): add a short random suffix to new card links.

Cross-cutting issue found while testing this group (affects groups 1, 3, 4, 8):

**HR-020 — High — Permissions — Event-triggered Livewire actions skip the permission guard**
- `ActiveLivewirePermissionMiddleware` checks only `calls[].method` (`app/Http/Middleware/ActiveLivewirePermissionMiddleware.php:30-36`). Methods reached through a Livewire event (`$listeners` / `#[On]`, called as `__dispatch`) never match the `config('access-control.livewire')` map. Any such method without its own check inside runs for anyone who can open the page.
- **Proven as the GM** (who holds `project-basics.view` but not `.export` or `.delete`), on Basic Settings, where the UI correctly shows no Add/Delete/Export buttons:
  - `Livewire.dispatchTo('basics.leave-type-table','exportExcel')` returned `leave_types_2026-11-18.xlsx` (10 KB).
  - `Livewire.dispatchTo('basics.course-category-table','delete',{id:318})` **deleted** my test category #318. No activity-log entry was written for it.
- Event-reachable methods with no check inside (script over `app/Livewire`):
  - **Delete:** AnnualLeaveRuleTable, ChecklistItemTable, ContractTypeTable, CourseCategoryTable, DepartmentTable, JobLevelTable, LeaveTypeTable, TerminationReasonTable.
  - **`toggleItemStatusConfirmed`:** CityTable.
  - **`exportExcel`:** the same tables plus CityTable, PositionTable, ContractsTable, EmpLeaveBalanceTable and EmployeeTable.
  - FK `RESTRICT` blocks deleting rows in use, but **annual-leave rules have no FK** (they drive entitlement) and a city can be switched off.
  - `NewsTable::delete` and `EmployeeTable::delete/toggle` are safe (they re-check inside). The contracts export has no pay amount.
- Who can exploit it today: the GM, and any user or role later given `project-basics.view` without the write permissions. The legitimate confirm flow also goes through `__dispatch`, so the config entries for these methods currently protect nothing.
- Suggested fix: (a) in the middleware, also resolve `__dispatch` calls to the listener method and apply the same map, and (b) add `authorizeActivePermission()` inside every delete, toggle and export (defence in depth), like `CourseCategoryModule::create` already does.

### 5. What works well
- **Scoping:** profile tiers are applied exactly as documented. Employee → directory only; manager → records tier for direct reports only; HR → full within the city. Pay never appears outside HR.
- **Forged-call protection:** drawer, profile section editing, employee delete and status toggle all re-check the policy and city server-side.
- **Self-service profile editing:** good validation messages next to the fields, a "Discard your changes?" guard, mobile sheet layout with proper gutters.
- **Empty "Save" on create** creates no draft (the autosave minimum works).
- **Status toggle** refuses drafts and awaiting-contract records.
- **Public ID card:** minimal data, noindex, throttled, uniform 404.
- **No horizontal scroll** on the profile or org chart at 390px. The employee table fits at 1440px.

---

## Group 3 — Contracts (P14–P18)

### 1. Page summary
| Page | Route | Files |
|---|---|---|
| Contracts list + drawer + renew modal | `/contracts` | `Livewire/Contract/ContractsModule, ContractsTable, ContractDrawer, ContractRenewModal` |
| Create / renew | `/contracts/create`, `/contracts/{id}/renew` | `ContractCreate`, `Concerns/DrivesContractComposer`, `Concerns/ResolvesContractLocationOptions`, `Forms/ContractForm` |
| Edit | `/contracts/{id}/edit` | `ContractEdit` |
| Details (signed document + amendments) | `/contracts/{id}` | `ContractDetails`, `ContractAmendments` |
| Amendment | `/contracts/{id}/amend` | `ContractAmend`, `Services/ContractAmendmentService` |
| PDF | `/contracts/{id}/export`, `-preview` | `ContractExportController`, `ContractPolicy::export` |

### 2. Role matrix
| Role | Access | Can see | Can manage | Blocked (server-side) | Notes |
|---|---|---|---|---|---|
| Employee | — | — | — | All contract URLs return 403 (incl. own contract) | Own contract history is visible on the profile instead |
| Manager (adonis) | "Team contracts" | **10 direct reports only**. Document shows "Pay details are hidden" | Nothing (no row actions, no buttons on details) | Another manager's report and other cities return 403 | ✔ read-only as designed |
| HR city (lina) | Full list (35, Latakia) | Everything incl. pay | Edit, Add amendment, Renew, Export PDF, auto-renew toggle, **Delete** (on active contracts too) | Forged on Aleppo contract #5: drawer `open`, renew `open`, `confirmToggleAutoRenew` all **403** ✔. `confirmDelete(5)` opens the dialog (no check) but the delete itself re-checks the policy | |
| HR-SYR | All cities | All | All | — | |
| GM (fredy) | List, details, edit, create, renew | Everything except pay: "Pay details are hidden. Your active role does not have access to pay figures… saving this form will not change it" | Edit and renew (pay preserved server-side) | Amend, export/preview (403); **no Export PDF button shown** ✔ | HR-008 stays Low: the UI never offers the export |

### 3. Modal inventory
| Modal | Who | Behaviour |
|---|---|---|
| Contract drawer | HR, GM, manager | Period, type, position, program, department, level, city, weekly hours, auto-renew; "Open full contract", Edit. Scoped ✔ |
| Renew modal | HR, GM | Renew as-is / with edits; policy re-checked ✔ |
| Auto-renew confirm | HR | Policy + `canAutoRenew()` re-checked ✔ |
| Delete confirm | HR | Generic "Delete Item…" (see HR-022) |
| Amendment page (stepper, sticky footer) | HR | 3 sections, "1 of 3 sections complete", Cancel / Save amendment |

### 4. Issues

**HR-021 — High — Validation / Logic — The first shift's working hours are never validated, and real contracts already have inverted hours**
- Page / role: contract create, edit, renew and amend, all HR roles and GM.
- `ContractForm::validateTerms()` validates the *second* shift (`work_hours_2_to` must be `after:work_hours_2_from`) but has **no rule at all for `work_hours_from` / `work_hours_to`** (`app/Livewire/Forms/ContractForm.php:340-381`).
- Evidence in `hr_db` (active contracts): #153 **21:00 → 17:00**, #163 **09:00 → 01:00**, #273 **08:00 → 04:00** (Hala Nseir's). The last two look like 12-hour-clock mistakes (01:00 meant 13:00). These times print on the signed contract ("from 9:00 AM to 1:00 AM"), and its "hours per day" clause prints **0 hours per day** for an inverted shift (`schedule-clause.blade.php:41-49,115`). The dashboard work-day clock can't read the schedule (`WorkdayClock::period()` returns null). *Corrected:* leave and timesheet daily hours come from `weekly_hours / 5` (`Contract::getWorkHourPerDayAttribute`), not from these times.
- Suggested fix: add `required_if fixed`, `date_format:H:i`, and `after:work_hours_from` (or an explicit "overnight shift" option), plus a data-fix list for the 3 contracts.

**HR-022 — High — Logic / Data loss — A contract with applied amendments can still be deleted, and its amendments go with it**
- Contracts list › More actions › **Delete**, HR roles, on any contract including active ones.
- `ContractsTable::delete` → `Contract::delete()` (no SoftDeletes). `ContractObserver::deleting()` deletes **every amendment including applied ones** (`app/Observers/ContractObserver.php:185-188`). The FK also cascades leave balances, pay tiers and renewal journeys.
- The only brake is the FK on leave requests (`RESTRICT`), so a contract whose employee never filed a leave request deletes cleanly, signed amendment history included. This contradicts the documented rule "never delete an amendment in effect".
- The confirmation is the generic "Delete Item — permanently delete the item?" without the employee, the period, or what goes with it.
- **Not executed** (real data).
- Suggested fix: refuse delete for any contract that is active, has applied amendments, or has leave balances in use. Keep delete for drafts / reservations only (termination is the normal path). Name the contract in the dialog.

**HR-023 — Low — UI (brand) — Disabled primary button is a pale pink**
- Amendment page, HR, 390px: "Save amendment" while disabled is a light pink fill. CLAUDE.md says to never tint the red into a pale/pink fill.
- Suggested fix: the disabled primary should use the neutral disabled style (surface fill + ink-3 text).

**HR-024 — Low — UI — The same validation message shows twice on the amendment page**
- Submitting the amendment for Celine's contract (no day range on file) shows "Weekly hours must be an explicit number for this contract category…" **twice**.
- Suggested fix: render the `weekly_hours` error in one place (field or section summary, not both).

**HR-025 — Low — Mobile / Consistency — Contract cards break dates mid-value, and date formats differ between views**
- Contracts list, HR, 390px: card text "Volunteer Contract · 2026-01-01 – 2026-" / "12-31" wraps inside the date. The 5 preset chips (Active, Ending soon, Terminated, Ended not renewed, Draft reservations) stack one per line and push the first card below the fold.
- Formats: desktop table "From 01-01-2026 To 31-12-2026", drawer "2026-01-05 – 2026-12-31", cards ISO.
- Suggested fix: one date format helper for all three, `whitespace-nowrap` on each date, and a horizontally scrolling chip row on phones.

**HR-026 — Low — Permissions (hygiene) — `confirmDelete` opens the dialog for a contract the user may not delete**
- Forged `confirmDelete(5)` as HR-LAT (Aleppo contract) opened the delete dialog. Confirming is refused by `Gate::authorize('delete')`, so nothing is lost, but the dialog shouldn't appear. Same pattern in the basic-settings tables (HR-020).
- Suggested fix: `Gate::authorize('delete', …)` in `confirmDelete` too.

### 5. What works well
- **Pay confidentiality is solid end to end:** figures are never sent to GM or manager browsers, the document says pay is hidden, `preserveHiddenPay()` strips pay keys on save, and pay tiers are carried forward for pay-blind roles. The Export button is hidden from pay-blind roles and the URL returns 403.
- **City scope on create, edit and renew** goes through `validateCityConsistency()` (`canAccessCity`, employee-in-city, `Position::inCity`). The earlier "invalid position in Syria" class of bug is covered.
- **Manager:** strictly read-only for direct reports.
- **Amendments:** policy re-checked on submit (`amend` / `decideAmendment`), a "nothing changed" guard, discard only for unapplied amendments, and an invalid submit creates nothing (verified: 0 amendments on #98).
- **Date rules:** end ≥ start, at most one year, termination ≥ start.
- **Layout:** no horizontal scroll and no clipped cards on the contracts list at 390px. The table fits at 1440px.

---

## Group 4 — Leave, balances, calendar, holidays, attendance (P19–P25)

### 1. Page summary
| Page | Route | Files |
|---|---|---|
| My leaves + request dialog | `/leaves` | `Employee/Leave/MyLeaveRequestModule, MyLeaveRequestTable`, `Forms/LeaveRequestForm`, `Services/LeaveRequestPricing` |
| All / team leaves + decision | `/leaves/all` | `AllLeaveRequestModule, AllLeaveRequestTable`, `Policies/LeaveRequestPolicy`, `Services/LeaveDecisionAuthority` |
| Balances | `/leaves/balance` | `EmpLeaveBalanceModule/Table`, `Forms/LeaveBalanceForm`, `Services/LeaveBalanceService` |
| Team calendar | `/team-calendar` | `Team/Calendar` |
| Holidays | `/holidays`, Basic Settings › Holidays | `Basics/HolidayModule` |
| Timesheets / print / PDF / Excel | `/attendance/timesheets`, `/print`, `/export/*` | `Attendance/Timesheets`, `Services/Attendance/*` |

### 2. Role matrix
| Role | Access | Can see | Can manage | Blocked (server-side) | Notes |
|---|---|---|---|---|---|
| Employee (celine) | My leaves, team calendar, own timesheet | Own requests. Balances per type in the request dialog. **Own timesheet only**: print with `city_id=5` still returns 1 sheet (hers) ✔ | Create / edit / delete own **pending** requests | Edit or delete of an accepted request refused by policy | |
| Manager (adonis) | Team leaves, team timesheets | Direct reports' requests | **Decide** (accept / reject with required reason). Decision stored with basis `manager` | `delete` refused (**403**, no `leaves.delete`) ✔ | |
| HR city (lina) | All leaves (city), balances, attendance + upload | City requests (with a "Seen by HR" column), 32 timesheets (Latakia); Aleppo print returns **404** ✔ | Mark reviewed, view, edit (incl. accepted), balance adjust, upload | No Holidays tab (HR-033) | **Can delete accepted requests by direct call; the UI shows no Delete** (HR-027) |
| HR-SYR / GM | — | (route access verified in group 1) | | GM: balances and upload 403 | |

### 3. Modal inventory
| Modal | Who | Behaviour |
|---|---|---|
| Request leave (5 steps: type → day/hour → dates → reason & cover → summary) | employee, manager, HR (own or for an employee) | Type cards show what's left. Unpaid is hidden while annual balance remains (explained in the dialog). Live summary |
| Submit confirmation | same | Type, period, duration, "left after this request (proposed)", estimate note. Buttons uneven (HR-032) |
| Leave decision | manager (and HR on behalf) | Shows balance now / this request / other pending / left if accepted. Accept or Reject; reject needs a reason ✔ |
| Leave details | all | Status, dates, decision date. No delete for accepted |
| Edit balance (sum / sub, day / hour, optional badge) | HR | See HR-029, HR-030 |

### 4. Issues

**HR-027 — High — Logic / Permissions — Deleting an accepted leave never gives the days back (and HR can do it although the UI hides it)**
- Steps (done with test data): E1 requested annual leave 23–24 Nov (#61). MG1 accepted it, so balance #547 `used` went 0 → **16 h**. As HR-LAT, `AllLeaveRequestTable::delete({id:61})` returned **200** and the request was deleted.
- Expected: deleting an accepted leave is refused, or it restores the 16 h. Actual: **`used` stayed 16**, so the employee permanently lost 2 days. I restored it by hand afterwards.
- Causes:
  - `LeaveRequestPolicy::delete` lets any `canManage` user delete in any status (`app/Policies/LeaveRequestPolicy.php:27-35`).
  - `AllLeaveRequestTable::delete` just calls `->delete()` (`app/Livewire/Employee/Leave/AllLeaveRequestTable.php:82-90`).
  - Nothing decrements `used`. Compare `LeaveRequestReview` (objection), which does `used - hours`, and the edit path, which applies the difference.
- The UI offers HR only Mark reviewed / View / Edit on an accepted row, so this is "blocked in the UI only".
- Suggested fix: allow delete only for Pending in the policy (cancel an accepted leave through the existing objection/review path), or restore `used` in a model `deleting` hook inside the same transaction.

**HR-028 — Medium — Validation — Hourly leave can be filed for a past date; day leave can't**
- Steps: E1 › Request leave › Annual › Hourly, date **2026-11-02** (16 days ago), 10:00–12:00. No error; the confirmation opened; request **#60 was stored as Pending**.
- Day leave on 10 Nov is correctly refused ("must be a date after or equal to 18-11-2026").
- Cause: `rules()` gives `start_at` `after_or_equal:today` but `leave_date` only `required|date` (`app/Livewire/Forms/LeaveRequestForm.php:136-153`).
- Suggested fix: the same rule for `leave_date`, or one deliberate retroactive policy for both types (e.g. sick leave filed late) that both enforce.

**HR-029 — Medium — Logic — Manual balance adjustments are wiped by the next recalculation, which may target the wrong contract**
- Balances › Edit (sum/sub) writes `deserved ± amount` directly (`app/Livewire/Forms/LeaveBalanceForm.php:78-83`). Recalculation then sets `deserved = calculated + amendment_offset` (`LeaveBalanceService::reprice`, line 223-226), so the manual change is lost.
- Recalculation runs on `leaves:recalculate` and whenever HR changes an employee's gender, marital status or join date (`app/Livewire/Employee/EmployeeEdit.php:199-205`). That code recalculates `contracts()->whereHas('leaveBalances')->first()`, the **first** contract with balances, not the current one.
- Code evidence only; not run against real balances.
- Suggested fix: store manual adjustments in their own column (like `overtime_credit` / `amendment_offset`) that `reprice()` adds back, and use `latestActiveContract()` in EmployeeEdit.

**HR-030 — Medium — Validation — Manual "subtract" has no floor and no ceiling**
- Same form: `amount` is `required|numeric|min:0` with no `max`, and subtracting doesn't check the result. `deserved` can go below `used` or below 0 (shown as a negative "available").
- Suggested fix: refuse when `deserved - amount < used`, and set a sensible max.

**HR-031 — Low — UI (pattern) — Validation messages appear twice**
- Leave dialog (past date, end before start, overlap) and amendment page (HR-024): every message is rendered twice, once at the field and once in a summary directly beside it.
- Suggested fix: show the summary only for errors whose field isn't visible.

**HR-032 — Low — UI — Uneven buttons in the submit confirmation**
- E1, 390px: "Cancel" sits right-aligned on its own line above a dark ink "Submit leave request", while the dialog behind uses a red primary "Submit leave request".
- Suggested fix: use the shared confirm-dialog footer (primary + outline side by side), as in the delete confirmation.

**HR-033 — Low — Permissions (question) — City HR can't maintain holidays**
- HR-LAT gets 403 on `/holidays` and has no Holidays tab in Basic Settings. Only HR-SYR and program managers can. Holidays change leave pricing and timesheets for that city.
- Suggested fix: confirm the intent. If city HR should own their city's holidays, scope the holiday module by city.

**HR-034 — Low — Logic (question) — Weekends print as "official holiday" with leave hours taken**
- Timesheet print, Oct 2026, Latakia (e.g. Bashar Sayegh): every Saturday and Sunday shows type "عطلة رسمية من المؤسسة", **6 hours in "ساعات الإجازة المأخوذة"** and 1 working day, while the note says "عطلة أسبوعية".
- Suggested fix: confirm with HR how the paper form should show weekends. If they're a weekly day off, label them that way and don't put hours in "leave hours taken".

**HR-035 — Low — Data (question) — Sick entitlement of 94 h on an 8-hour day**
- Celine (8 h/day): Annual 96 h (12 days), but Full and Partial Sick and Unpaid each 94 h, shown as "11 days and 6 hours". It looks like a rounding of a prorated value.
- Suggested fix: confirm the rule. If sick leave should be whole days, round to whole days at the end.

**Re-test against the current code (2026-11-26, after 084–089, 085 and the UX pass 954822e0):**
| ID | Status now | Evidence |
|---|---|---|
| HR-027 | **Still present** | `LeaveRequestPolicy::delete` still lets a manager or HR delete in any status, and `AllLeaveRequestTable::delete` still only calls `->delete()`. Nothing gives back `used` (the only decrements are the edit delta and `LeaveRequestReview`). Code unchanged since the proven repro. |
| HR-028 | **Still present, now inconsistent** | 085 made backdating deliberate: `mayBackdate()` lets only HR on All Leaves date a **day** leave in the past. `leave_date` (**hourly**) is still only `required|date`. Reproduced as an employee on My Leaves: an hourly leave on 2026-11-10 was accepted and saved (#68, removed afterwards). |
| HR-029 | **Still present** | `LeaveBalanceForm::updateAnnualLeaves` writes `deserved` directly; `reprice()` overwrites it; `EmployeeEdit:201` still uses `contracts()->whereHas('leaveBalances')->first()`. |
| HR-030 | **Still present** | `amount` is still `required|numeric|min:0`, with no ceiling and no check against `used`. |
| HR-031 | **Not a bug** (same as HR-024) | Day leave in the past: the field message is in view and the summary entry is off screen, so the user sees it once. The message is now clearer ("A leave cannot start on a day that has passed… for past sick [leave, ask HR]"). |
| HR-032 | **Resolved by others** | The employee form no longer has the second confirmation dialog ("the send button now sends", 954822e0). HR's All Leaves still confirms, through the shared confirm dialog. |
| HR-033 | **Unchanged** (question) | `HolidayProjects::canManage` = national HR or a project manager; city HR still can't manage holidays. |
| HR-034 | **Intentional** (wording question) | `Timesheet` deliberately treats a weekend as a paid day off (`paid_off_day = true`, a full day's leave hours, 1 working day). The row reads "عطلة رسمية من المؤسسة" (key `weekly-holiday`), the note "عطلة أسبوعية". Only the wording is for HR to confirm. |
| HR-035 | **Unchanged** (question) | Celine: Annual 96 h, Sick / Partial sick / Unpaid 94 h ("11 days and 6 hours"). |
| Attendance workflow | **Still untestable here** | `hr_db` still has 0 punches and 0 attendance months. |

**Not testable here:** the attendance month workflow (confirm → approve → lock → overtime credit → reopen) and the upload. `hr_db` has **no punches and no attendance months**, and uploading a device report would write punches for real employees. This needs a seeded copy, or your go-ahead to upload a test file.

### 5. What works well
- **Duplicate protection:** a double-clicked Submit created **one** request (#61), thanks to the submission token and lock.
- **Overlap check** with a clear message naming the existing request. Past start date and end-before-start are refused.
- **Pending requests are reserved in the preview** ("9 days and 6 hours left" after a 2-hour pending request).
- **Decision dialog** gives the manager the full balance picture. A reason is required for a rejection. The decision stores the basis (`manager`).
- **Accept deducts the right hours** (16 h for 2 days of 8 h).
- **Manager can't delete** (403). An employee can't edit or delete an accepted request.
- **Timesheet scope is correct for every role tested:** employee 1 sheet, HR-LAT city only, another city 404. The print is marked "مسودة" while days are undecided.

---

## Group 5 — Checklists and deliverables (P26–P29)

### 1. Page summary
| Page | Route | Files |
|---|---|---|
| My items | `/checklist-items/all` | `ChecklistItems/AllItems` |
| Pending delivers / returns (+ counters) | `/checklist-items/pending-delivers`, `/pending-returns` | `PendingItems`, `PendingReturns`, `*Counter`, `Services/ChecklistWorkflow` |
| Done requests | `/checklist-items/done-requests` | `DoneRequests`, `DoneRequestsTable` |
| Default deliverables | `/default-deliverables` | `ProgramManagement/DefaultDeliverable/*` |
| Assigning items to an employee | Employee edit › Checklist tab | `Forms/Employee/ChecklistForm`, `EmployeeEdit::saveChecklist` |

Data available: only **2 checklist rows** (both Aleppo) and 3 default deliverables, so the hand-out → return cycle was checked through code and forged calls, not run end to end.

### 2. Role matrix
| Role | Access | Can see | Can manage | Blocked (server-side) | Notes |
|---|---|---|---|---|---|
| Employee | My items | Own items only ("No checklist items yet") | — | Pending, done and default pages return 403 | |
| Manager | My items | Own | — | Same as employee | |
| HR city (lina) | All four pages (menu hides pending-delivers/returns unless "deliver responsible") | Done requests and default deliverables **scoped to Latakia** (the Aleppo rows aren't shown) ✔ | Default deliverables CRUD (city-checked), assign items on employee edit | Forged `markDelivered(2)` (Aleppo, deliverer is employee 428) returned **404**, record unchanged ✔ | Pending pages open by URL but only show items where *you* are the nominated person |
| HR-SYR | All | All cities | All | — | Sees "Pending Delivers / Returns" in the menu |
| GM | Done requests, default deliverables (`checklists.manage`) | — | — | — | |

### 3. Modal inventory
| Modal | Who | Behaviour |
|---|---|---|
| Default deliverable add/edit | HR, GM | City is checked *before* validation, then `Gate` create/update ✔. Discard guard |
| Delete default deliverable | HR | `Gate::authorize('delete')` inside the event handler ✔ (the right pattern for HR-020) |
| Initiate return / finalize (employee edit) | HR | Through `ChecklistWorkflow` |

### 4. Issues

**HR-036 — Medium — Validation / Permissions — Checklist assignment accepts any deliverer or receiver ID**
- Employee edit › Checklist tab, HR / GM.
- `ChecklistForm::save()` writes `delivered_id` and `receiver_id` straight from the browser (`app/Livewire/Forms/Employee/ChecklistForm.php:72-98`). There's no rule that the person exists, is active, or is in the employee's city. `EmployeeEdit::saveChecklist` also loads `DefaultDeliverable::all()` (every city) to pre-fill deliverers (`app/Livewire/Employee/EmployeeEdit.php:104`).
- Effects:
  - An item can be assigned to a deliverer in another city, or to a former employee. They get a notification and the item waits in their queue forever.
  - A nonexistent ID fails on the foreign key (server error instead of a message).
- **Not executed:** a save would notify a real person.
- Suggested fix: validate both IDs against active employees in the employee's city (the same query that fills the dropdown), and scope the defaults to the employee's city.

**HR-037 — Low — UI / Mobile — Done requests: empty state squeezed into an 88px column, and the page has no header**
- `/checklist-items/done-requests`, HR-LAT, 390px. "No completed requests yet / Delivered and returned items will appear here" renders in a box **88px wide inside a 357px cell**, about one word per line. The same component on Contracts is 325px wide, which is correct. The difference is that Done requests has no `cardView()` for phones.
- The page also has no breadcrumb / page header (blank band under the title), unlike every other list.
- Suggested fix: give `DoneRequestsTable` a `cardView()` like the other lists (or `w-full` on the empty state inside table cells), and use `<x-page-header>`.

**HR-038 — Low — Data — Typos and inconsistent identifiers in reference data**
- Checklist item "**Recieve** Electronics" (should be "Receive").
- Employee 428's username is `youssef..kharbout` (double dot), which appears in the profile URL.
- Employee 347's username `hikmat.joubi` vs login `hekmat.jouby@hcsyria.org`.
- Suggested fix: correct the data. Optionally add a username format rule (`/^[a-z0-9]+(\.[a-z0-9]+)*$/`).

### 5. What works well
- **The deliver/receive workflow is re-scoped on the server** to the nominated person and to the pending state (`ChecklistWorkflow::transition`). Forging another person's ID changes nothing.
- **Default deliverables:** city scope on the list, the export and every write. The delete is policy-checked *inside* the event handler.
- **Empty states** on My items / Pending / Returns are clear and use the shared component.

---

## Group 6 — News, badges, message templates, mail lists, folders (P34–P38)

### 1. Page summary
| Page | Route | Files |
|---|---|---|
| News list / create / edit / show / preview | `/news…` | `News/*`, `Forms/NewsForm`, `Support/News/NewsAccess` |
| Badges | `/badges` | `Badges/BadgesModule, BadgesTable`, `BadgePolicy` |
| Message templates | `/message-templates` | `Messages/TemplateManager` |
| Mail lists | `/mail-lists` | `MailLists/Index` |
| Folders (+ file preview / download) | `/folders/{id?}`, `/folder-files/{media}/…` | `Archives/ArchivedFilesModule`, `ArchiveFileController` |

### 2. Role matrix
| Role | Access | Can see | Can manage | Blocked (server-side) | Notes |
|---|---|---|---|---|---|
| Employee (celine) | Mail lists, folders, news items (via dashboard / link) | 26 mail lists with addresses and member counts. Own and shared folders | Own folders | `/news` (list) 403. **Another user's folder file: download / preview / office-preview all 403. `/folders/3` 403.** Forged `downloadFile`, `previewFile`, `editPermissions`, `startEditingFolder`, `confirmFolderDeletion`, `confirmFileDeletion` all **403** ✔ | |
| Manager (adonis) | + News management | Own news | **Create and publish news to any audience, including "All employees"** (HR-040) | Badges, templates 403 | Edit/delete limited to own items (`NewsAccess::canManage`) |
| HR city | All above + badges, templates | Badges scoped to visible employees | Badges CRUD, templates, mail-list members | — | |
| HR-SYR | All | **Every user's private folders and files** (HR-039) | All | — | |
| GM | News, badges (read-only) | All | News CRUD | Templates 403 | |

### 3. Modal inventory
| Modal | Who | Behaviour |
|---|---|---|
| Badge details / delete | HR | Delete validates the ID, then `findVisibleBadge` + `Gate` inside the event handler ✔ |
| News preview | news managers | Rendered from the unsaved form; never publishes |
| Folder permissions / rename / delete confirmations | folder owner | Every one re-checks ownership (403 for others, verified) |
| Mail list members | everyone (view); `mail-lists.manage` (edit) | Writes call `authorizeActivePermission` / `authorizeConfirmer` inside |

### 4. Issues

**HR-039 — Low — Privacy (question) — The super-admin can open every employee's private folders**
- `ArchiveFileController::authorizeMediaAccess` allows `isActiveAdministrator()` for any folder file (`app/Http/Controllers/ArchiveFileController.php:60-71`). `ArchivedFilesModule::loadContent` lists *all* other users' non-shared folders to that user under "Shared with me" (`app/Livewire/Archives/ArchivedFilesModule.php:203-215`).
- *Corrected in group 7:* `User::isActiveAdministrator()` is **super-admin only** (`app/Models/User.php:249-252`). It is not `config('access-control.administrator_roles')`, so HR-SYR does **not** get this bypass. Real examples in `hr_db`: folders named "Fadi", "Rita" and "Test", owned by individual users.
- Suggested fix: decide the policy. If "My folders" is personal space, drop the bypass (or make it an audited support mode). If it isn't, say so on the page.

**HR-040 — Medium — Permissions (question) — Any manager can publish news to the whole organisation, with no approval**
- News › New content, Manager (adonis), 1440px. The Audience field offers "All employees" (selected by default), Selected cities, centers, programs, departments and employees. Nothing limits a manager to their own team or program, and publishing needs no approval. `NewsAccess::canCreate` allows any `manager`, and `NewsForm` validates targets only against "what is offered" (`app/Livewire/Forms/NewsForm.php:116-121`), which isn't scoped to the author.
- **Not published.**
- Suggested fix: limit managers' audience options to their own reports / program / center (and validate the same list), or let managers save drafts only and have HR publish.

**HR-041 — Low — Logic — Shared-folder filter treats "someone else's private folder" as shared**
- In "Shared with me", `loadContent` includes folders where `is_shared = false AND user_id != me`. For non-administrators the extra `whereHas('users', me)` keeps it correct. The meaning relies on that second condition, so it's easy to break; HR-039's bypass is exactly that case.
- Suggested fix: express "shared with me" as "I'm in `folder_user` and I'm not the owner", without the `is_shared` OR branch.

### 5. What works well
- **Folder files are well protected:** wrong-user access to download, preview and office-preview, and every forged folder action, returned 403. Download names and content types are sanitised (`safeDownloadName`, `safeContentType`).
- **Employee documents** go through `viewPrivateDocuments` (self, or HR with `employees.update` in scope).
- **Event-driven deletes are done right here:** badges (`findVisibleBadge` + `Gate`), news (`NewsAccess::canManage`), default deliverables. Writes on mail lists re-check permissions inside. These are the models for fixing HR-020.
- **News list** has clear state tabs (All / Drafts / Scheduled / Published / Expired / Archived) and the honest note "Publishing to LinkedIn is coming later".

---

## Group 7 — Organisation structure, settings, reports, administration (P30–P33, P39–P42)

### 1. Page summary
| Page | Route | Files |
|---|---|---|
| Positions | `/positions`, `/create`, `/{id}/edit` | `Position/PositionsModule, PositionTable, PositionCreate, PositionEdit` |
| Centers | `/centers`, `/create`, `/{id}` | `ProgramManagement/Center/*` |
| Programs | `/programs`, `/create`, `/edit/{slug}`, `/{slug}` | `ProgramManagement/Program/*`, `Forms/ProgramManagement/ProgramForm` |
| Basic settings (10 tabs) | `/basic-settings?tab=` | `Basics/*` |
| Statistics | `/statistics/general`, `/statistics/contract` | `Statistics/*`, `Dashboard/*Statistics`, `Support/StatisticsCache` |
| Activity log | `/activity-log` | `ActivityLog/*`, `Traits/RedactsSensitiveActivityLog` |
| Access management | `/access-management` | `AccessManagement/Index` |

### 2. Role matrix
| Role | Access | Can see | Can manage | Blocked (server-side) | Notes |
|---|---|---|---|---|---|
| Employee | Org chart only | — | — | Positions, centers, programs, basic settings 403 (group 1) | |
| Manager | Centers, programs (read) | — | — | Positions, basic settings 403 | |
| HR city (lina) | All structure pages, basic settings, statistics | **Positions: 23 rows, all Latakia** ✔. **Centers: Latakia only** ✔ (Aleppo center 403). Contract statistics: city filter offers "Latakia" only ✔ | Positions and centers in the city. **Programs (nationwide) and basic-settings tables (nationwide) — edit** (HR-044) | Aleppo position edit 403, Aleppo center 403. Activity log, access management 403 | 4 general statistics charts are nationwide (HR-043) |
| HR-SYR | Everything incl. activity log, access management | All | All | Can't promote anyone to super-admin, can't grant a permission it doesn't hold, can't edit its own access (`assertMayAssignRoles`, `assertMayGrantPermissions`, self-edit guard) ✔ | |
| GM | Structure pages read, programs create/edit, basic settings read | All | Programs | Positions/centers create 403. Basic-settings writes hidden **but reachable through events** (HR-020) | |

### 3. Modal inventory
| Modal | Who | Behaviour |
|---|---|---|
| Basic-settings add/edit dialogs (10 tables) | HR, HR-SYR | `create`/`edit`/`save` are guarded. Delete, toggle and export are not (HR-020) |
| Position form | HR | City-scoped options |
| Center form (weekend days) | HR | Weekend days wrap correctly (fixed in 077) |
| Access management: assigned users of a role | HR-SYR | Listed per role |

### 4. Issues

**HR-042 — Medium — Security (pay confidentiality) — Old activity-log entries still hold salaries in plain text**
- `hchr_activity_log` has **12 `Contract` entries (latest 2026-06-29) whose `properties` contain `"salary": "<amount>"`** (e.g. `"400.00"`). They predate `RedactsSensitiveActivityLog`, which today records only `sensitive_fields_changed`.
- Impact:
  - A copy of the database, the threat the separate `SALARY_ENCRYPTION_KEY` was introduced for, exposes these figures without any key.
  - The Activity Log page shows them to **super-admin, the role deliberately barred from pay** (`config('salary.blind_roles')`).
- Suggested fix: a one-time migration that strips `salary`, `salary_alt` and pay-tier `amount` from `properties.attributes/old` of past `Contract` / `ContractPayTier` / amendment entries and adds `sensitive_fields_changed`. Optionally treat `Badge.amount` the same way.

**HR-043 — Low — Permissions (scope) — Four General-statistics charts show nationwide figures to city HR**
- Statistics › General, HR-LAT: "Contracts type", "Departments", "Job levels" and "Payment type" are computed from all cities. `ContractTypeEmployeesStatistics:71`, `DepartmentEmployeeStatistics:62`, `JobLevelEmployeeStatistics:71` and `SalaryPerEmployeeStatistics:71` call `StatisticsCache::remember(...)` with no `cityId` and an unscoped query. Contract statistics on the next page *is* scoped (city filter offers "Latakia" only).
- Only counts, no names or amounts, but inconsistent with every other HR-LAT page.
- Suggested fix: pass the active role's city to the query and to `StatisticsCache::remember(..., $cityId)`, as the contract statistics do.

**HR-044 — Low — Permissions (question) — City HR can change organisation-wide reference data**
- HR-LAT (Latakia only) holds `programs.update` and `project-basics.create/update/delete`. It can edit any **program** (e.g. `/programs/edit/CU`, Children University, which runs in several cities) and every Basic Settings table: cities, contract types, **leave types, annual-leave rules**, termination reasons, job levels, departments. All of these apply to every city. `Program/Update::save()` has no check of its own beyond the route permission.
- Suggested fix: decide who owns organisation-wide data. If it's head office, keep these write permissions for HR-SYR only and give city HR read access.

**HR-045 — Low — Data — English title stored in Arabic**
- Positions, HR-LAT: one Latakia position has Title (EN) "مختص" (the same as its Arabic title). It shows in Arabic in English lists and documents.
- Suggested fix: correct the record. Optionally validate that `*_en` fields contain Latin script.

### 5. What works well
- **Access management can't be abused for escalation:** only a super-admin can assign administrator roles, nobody can grant a permission they don't hold, nobody can edit their own access, and every change is validated before anything is written. These are good, well-commented safeguards.
- **Position and center city scope** is consistent between list, detail and edit.
- **Contract pay is redacted in all new activity-log entries.**
- **Contract statistics** are city-scoped, with a city filter limited to the role's cities.

---

## Group 8 — Recruitment (P44–P51)

Scope note: data available was 23 requests, 15 campaigns (Aleppo + Latakia), 16 applicants, 21 applications, 24 interviews, 16 offers and 14 CV files. **No public link is currently valid** (all expired), so the public application form wasn't submitted. The full hire conversion (offer → employee + contract) wasn't run, because it creates real records.

### 1. Page summary
| Area | Routes | Files |
|---|---|---|
| Requests, approvals | `/recruitment/requests…`, `/recruitment/approvals` | `Recruitment/Requests/*`, `Approvals/*`, `Services/RecruitmentApprovalService`, `RecruitmentWorkflowInstanceService` |
| Campaigns, job posts, forms, invites, public links | `/recruitment/campaigns…` | `Recruitment/Campaigns/*`, `JobPosts/Editor`, `ApplicationForms/Editor`, `PublicLinks/Manager` |
| Applicants, files | `/recruitment/applicants…`, `/recruitment/applicant-files/{id}/download` | `Recruitment/Applicants/*`, `RecruitmentApplicantFileController`, `RecruitmentApplicantFilePolicy` |
| Interviews, offers | `/recruitment/interviews…`, `/recruitment/offers…` | `Recruitment/Interviews/*`, `Offers/*`, `RecruitmentJobOfferPolicy` |
| Export, erasure | `POST /recruitment/applicants/export`, `/{id}/erasure` | Controllers + `RecruitmentApplicantPolicy` |

### 2. Role matrix
| Role | Access | Can see | Can manage | Blocked (server-side) | Notes |
|---|---|---|---|---|---|
| Employee | — (unless an interview participant) | — | — | All recruitment pages 403 (group 1) | |
| Manager (adonis) | Requests, applicants, interviews, offers | Own requests (154 ✔; 151 by Hala → 404; 153 by GM → 404). Applicants of his campaigns (#147, #109). Interviews he takes part in (45, 61). **Offer 173 with pay "500.00 USD / Month"**: allowed, because adonis was personally granted `view-offer-salary` (documented design, `RecruitmentJobOfferPolicy::viewSalary`) | Raise, submit, cancel requests; endorse, tag | Applicant 367 / 7 → 404, offer 171 → 404, campaigns 403 (HR-009), approvals 403 (not an eligible approver) | |
| HR city (lina) | All recruitment pages except indicator rules | **Latakia only. Every Aleppo record (campaign 108, applicant 7, interview 11, offer 2, request 153, invites) returned 404**, Latakia ones 200 ✔ | Campaigns, applicants, interviews, offers | CV file download 403 while the scan is "pending" (HR-046). Export and erasure need explicit per-user grants ✔ | |
| HR-SYR | All incl. matching rules | All cities | All | — | |
| GM | Requests, approvals, campaigns, applicants, interviews, offers | — | Raise / approve requests (as the assigned approver), endorse | — | |

### 3. Logic checks (code + data)
- **Approval chain:** only the *assigned* approver of the *current* step may act, and an outdated version is refused (`RecruitmentApprovalService:245-262`). A manager who is also the requester has their step **skipped, not self-approved**. The single finance approver asking for themselves skips that step. A requester with no approving manager gets a clear error (`recruitment-workflow.missing_manager`).
- **Offer pay:** HR only, through `canViewSalary`. Otherwise only a *sent* version, to the *requesting* manager, with an explicit grant, and never a salary-blind role ✔.
- **CV files:** scan status must be `clean`, plus permission or assignment, plus scope ✔.
- **Public link tokens:** stored hashed, URL encrypted ✔. Unknown tokens → 404, throttled.

### 4. Issues

**HR-046 — Low — Other / UX — CVs stay "being scanned" forever when the scanner isn't running, and nobody is told**
- Applicant page, HR-LAT, 390px: "CV being scanned" / "Scanning", for a file uploaded on 2026-10-01. **All 14 CV files in `hr_db` are `scan_status = pending`** (uploads from 2026-09-24 to 10-06). The download is correctly refused until the file is clean, so in this state **HR can't open any CV**.
- Cause here: no scheduler / ClamAV locally (`recruitment:scan-files` runs every 15 minutes, `routes/console.php:67`). The same happens on any host where ClamAV or the scheduler stops.
- Suggested fix: show the scan's age ("waiting 7 weeks — contact IT") and alert administrators when a file has been pending for more than an hour. Verify ClamAV and the scheduler on production with a health command.

### 5. What works well
- **City scope across the whole module** (campaigns, applicants, interviews, offers, requests, invites) is consistent and returns 404 (not 403), so out-of-scope records aren't even confirmed to exist.
- **The approval workflow** is well designed (assigned approver, current step, version check, self-approval and missing-manager handling).
- **Sensitive acts** (export, erasure, indicator rules) need explicit per-user grants and are excluded from bulk role grants.
- **The listener audit (HR-020) found no write handler in recruitment that skips its own check.** Only refresh listeners are event-reachable.

---

# Final summary

## Issues by severity
| Severity | Count | IDs |
|---|---|---|
| Critical | 1 | HR-013 |
| High | 5 | HR-014, HR-020, HR-021, HR-022, HR-027 |
| Medium | 9 | HR-001, HR-015, HR-016, HR-028, HR-029, HR-030, HR-036, HR-040, HR-042 |
| Low | 31 | HR-002–012, 017–019, 023–026, 031–035, 037–039, 041, 043–046 |
| **Total** | **46** | |

## Issues by category
| Category | Count | IDs |
|---|---|---|
| UI / Mobile | 15 | 001–006, 012, 017, 018, 023, 024, 025, 031, 032, 037 |
| Permissions | 11 | 008, 009, 014, 019, 020, 026, 033, 039, 040, 043, 044 |
| Logic / Data loss | 7 | 013, 015, 022, 027, 029, 034, 041 |
| Validation | 6 | 011, 016, 021, 028, 030, 036 |
| Data quality | 3 | 035, 038, 045 |
| Security (pay) | 1 | 042 |
| Consistency | 1 | 007 |
| Other | 2 | 010, 046 |

## Top 10
1. **HR-013:** deleting an employee permanently wipes contracts, leave, attendance, history **and the login account**. One generic confirm; available to city HR on active staff.
2. **HR-020:** Livewire actions reached through events skip the permission guard. **Proven:** the GM deleted a basic-settings record and exported data without permission.
3. **HR-014:** any family-member record in any city can be deleted by ID from one editable employee. **Proven** cross-city.
4. **HR-027:** deleting an accepted leave never returns the days (proven: 2 days lost), and HR can do it though the UI hides it.
5. **HR-022:** deleting a contract with applied amendments deletes the signed amendment history.
6. **HR-021:** shift hours are never validated. **3 live contracts already have inverted hours** (#153, #163, #273), printed on signed contracts.
7. **HR-042:** 12 old activity-log rows hold salaries in plain text, defeating the separate salary key and visible to the pay-blind super-admin.
8. **HR-029:** manual leave adjustments are wiped by the next recalculation, which can also target the employee's *first* contract instead of the current one.
9. **HR-028:** hourly leave can be filed for past dates (day leave can't).
10. **HR-036:** checklist deliverer and receiver aren't validated (other city, former staff, or a nonexistent ID causing a server error).

## Cross-page inconsistencies (patterns)
- **Hard delete everywhere, with one generic dialog:** employees, contracts, accepted leave and reference data are deleted permanently. The dialog always says "Delete Item — permanently delete the item?" without naming the record or what goes with it (HR-013, 022, 027, 020).
- **Authorization inside event handlers is inconsistent:** badges, news, default deliverables, employee delete and contract delete re-check inside (correct). The 8 basic-settings tables, the city toggle and 5 exports don't (HR-020). `confirmDelete` opens dialogs without a check (HR-026).
- **"Blocked in the UI only":** HR can delete accepted leaves (hidden button, server allows it, HR-027). The GM can delete or export basic settings (HR-020).
- **Validation display:** messages shown twice (leave dialog, amendment page: HR-024, 031). Raw attribute names and a wrong message on create employee (HR-016). Error summary below the Save button (HR-017).
- **Dates in three formats** in the same module (table `01-01-2026`, drawer `2026-01-05`, cards ISO; HR-025).
- **Mobile:** fixed-height tiles clip content (HR-001, 002). Bottom-nav labels overflow or wrap (HR-003). Headers and toolbars fill the first screen (org chart HR-018, contract presets HR-025, dashboard dock HR-004). Empty state squeezed (HR-037).
- **Brand rules:** two solid primary buttons side by side (HR-012), a pink disabled primary (HR-023), a dark-ink submit in one confirm dialog (HR-032).
- **City scope for reference data and aggregates:** city HR can edit nationwide programs, leave types and annual-leave rules (HR-044), sees nationwide charts (HR-043), but can't manage its own city's holidays (HR-033).
- **Config vs database drift:** role baselines in `config/access-control.php` differ from the DB (manager lacks campaigns, HR-009). The GM's `contracts.export` can never take effect (HR-008).

## Permission gaps
| Gap | Who | Server-side? | ID |
|---|---|---|---|
| Delete/toggle/export basic-settings tables without the permission | GM (any role with `project-basics.view`) | **No, exploitable** | HR-020 |
| Delete another city's family-member records | City HR, GM | **No, exploitable** | HR-014 |
| Delete accepted leave (balance not restored) | HR roles | Allowed by policy; UI hides it | HR-027 |
| Hard-delete active employees and their accounts (incl. super-admin's record) | City HR | Allowed by design | HR-013 |
| Publish news to the whole organisation | Any manager | Allowed by design | HR-040 |
| Edit organisation-wide reference data | City HR | Allowed by design | HR-044 |
| Pay-blind super-admin reads old salaries in the activity log | super-admin | Data issue | HR-042 |
| Open everyone's private folders | super-admin | Allowed by design | HR-039 |
| Can't manage own city's holidays | City HR | Blocked | HR-033 |
| Campaigns hidden from managers (DB drift from config) | Manager | Blocked | HR-009 |

## Recommended fix order
1. **Safe deletes** (one PR): HR-013, HR-022, HR-027. Restrict to drafts / pending only (or soft-delete), restore leave balance in the same transaction, refuse records with history, and name the record and its consequences in a shared delete dialog.
2. **Server-side authorization** (one PR): HR-020 (middleware resolves `__dispatch` + `authorizeActivePermission` in each handler), HR-014 (scope `RelativeForm`), HR-026, HR-036. Add tests that call each handler as an unauthorised role.
3. **Pay confidentiality:** HR-042 migration to strip pay from old activity-log rows. Run `salary:key:check` afterwards.
4. **Contract and leave validation:** HR-021 (+ fix contracts #153, #163, #273), HR-028, HR-030, HR-016, HR-011.
5. **Leave balance integrity:** HR-029 (stored adjustment column, `latestActiveContract()`).
6. **Org chart multi-position:** HR-015.
7. **UI / mobile batch:** HR-001–006, 012, 017, 018, 023–025, 031, 032, 037, 007, 010.
8. **Decisions for you, then small changes:** HR-008, 009, 033, 034, 035, 039, 040, 043, 044, 046.
9. **Data cleanup:** HR-038, HR-045, and test leave #60 (see the log).

## Not covered / limits
- **Attendance month workflow** (confirm → approve → lock → overtime credit → reopen) and **report upload**: no punches in `hr_db`. Needs a seeded copy or an approved test upload.
- **Public application form** (no valid link) and **offer → hire conversion** (creates real records).
- **super-admin** and the other city HR roles (HMS, DMS, ALP beyond the switch test) weren't logged into. City scope was verified with HR-LAT / HR-TAR.
- Viewports: every page at 390px; 1440 / 1024 / 768 / 360 measured on the dashboard, and 1440 on list pages. Screenshots are described, not saved.
- HR-013, HR-022, HR-029 and HR-036 are from code and schema evidence (not executed, to protect real data). HR-014, HR-020, HR-021 (data), HR-027 and HR-028 were reproduced.

---

## Test Data Log
| # | When | Role / account | Page | Record | ID | What was done | Undo |
|---|---|---|---|---|---|---|---|
| 1 | 2026-11-18 | lina.sayegh | Role switcher | `hchr_model_has_roles.is_active` | user 539 | HR-LAT → HR-TAR → HR-LAT (to test stale tabs) | Already restored to HR-LAT |
| 2 | 2026-11-18 | all test accounts | Login | `users.last_seen_at`, sessions | — | Sign-in/sign-out only | None needed |
| 3 | 2026-11-18 | DB (setup) → GM fredy | Basic Settings › Course categories | `course_categories` | 318 | Inserted "QA TEST - delete me", then deleted by the GM through the event bypass (HR-020) | None: row gone, net zero |
| 4 | 2026-11-18 | GM fredy | Basic Settings › Leave types | Excel export | — | `leave_types_2026-11-18.xlsx` downloaded in the browser pane (reference data) | Delete the file from the pane's downloads if present |
| 5 | 2026-11-18 | DB (setup) → HR-LAT lina | Employee edit (405) | `relatives` | 1 (employee 24, Aleppo) | Inserted "QA TEST relative - delete me", then deleted from a Latakia edit page (HR-014) | None: row gone, net zero. Employee 405 unchanged (the blank form row was never saved) |
| 6 | 2026-11-18 | celine.khoury | My profile | — | — | Invalid personal-contact values submitted (rejected), then discarded | None: nothing saved |
| 7 | 2026-11-18 | lina.sayegh (HR-LAT) | Contract amend #98 | — | — | Amendment submitted with only an effective date: rejected by validation, 0 amendments created (verified in the DB) | None |
| 8 | 2026-11-18 | lina.sayegh (HR-LAT) | Contracts list | — | — | Delete dialog opened (own city and forged #5) and **cancelled**; no contract deleted | None |
| 9 | 2026-11-18 | celine.khoury | My leaves | `leave_requests` | **60** | Hourly annual leave 2026-11-02 10:00–12:00, "QA test - past", **Pending: still in the DB** (my delete was blocked by the auto-mode safety check) | **Delete #60** (pending, so no balance effect) |
| 10 | 2026-11-18 | celine → adonis → lina | My leaves / Team leaves | `leave_requests` | 61 | Created (23–24 Nov), accepted by adonis, deleted by HR-LAT (HR-027) | Row gone |
| 11 | 2026-11-18 | DB (restore) | — | `leave_balances` | 547 (Celine, Annual) | `used` left at 16 by HR-027, **reset to 0 by hand** (original value) | Done |
| 13 | 2026-11-18 | lina.sayegh (HR-LAT) | Pending delivers | `checklists` | 2 | Forged `markDelivered(2)`: refused (404), row unchanged (still pending) | None |
| 14 | 2026-11-18 | lina.sayegh (HR-LAT) | Contracts list | table state | — | Search "zzzqqq" typed to test the empty state, then cleared (35 results again) | None |
| 15 | 2026-11-18 | celine.khoury | Folders | — | folder 3, media 577/578 | Forged access to another user's folder and files: all refused (403), nothing changed | None |
| 16 | 2026-11-18 | adonis.mowas | News › New / Mail lists | — | — | Opened the create form (not saved). Forged mail-list writes (code re-checks permissions) | None |
| 17 | 2026-11-18 | lina.sayegh (HR-LAT) | Positions / centers / programs / statistics | — | — | Read-only probes (pages and URLs); nothing saved | None |
| 18 | 2026-11-18 | lina.sayegh, adonis.mowas | Recruitment | — | — | Read-only probes of campaigns, applicants, interviews, offers, requests, files; nothing saved | None |
| 19 | 2026-11-26 | celine.khoury | My leaves (re-test HR-028) | `leave_requests` | 68 | Hourly leave 2026-11-10 saved (the form now sends without a confirmation step); removed from the DB with #60. A notification to Adonis remains and opens as "no longer available" | Done |
| 12 | 2026-11-18 | system | — | `notifications` | 5 rows | New-request / decision notifications for #60 and #61 (to Celine, Adonis, HR) | Optional: delete notifications created 2026-11-18 14:40–15:00 that mention the QA tests |

No other records created or changed. I didn't decide any existing leave request: the pending requests on the dashboards are real data and will stay untouched. Workflow tests will use leave requests I create and log here.
