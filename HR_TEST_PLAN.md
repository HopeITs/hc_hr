# HR Process — QA / UX / Authorization Test Plan

Status: **Phase 1 (plan) — awaiting approval.** No code, config, migration or seeder has been changed.
Date: 2026-11-18 · Branch: `hr-ux-by-rafi` · Target: **local only**, `http://hc-hr.test` (Laragon, MySQL `hr_db`). Never production.

---

## 0. Ground rules (Phase 0)

- Study and report only. Findings go to `HR_TEST_REPORT.md`, page by page, and wait for approval before any fix.
- Data-changing tests are allowed (leave requests, decisions, drafts…); every record created/changed is logged in §7 *Test Data Log* (type, ID, role, page, how to undo). No deletes / resets / purges of anything not created by the test.
- Every finding needs evidence: page + role + viewport + steps, plus a screenshot description, console/network output, or `file:line`. Hypotheses below (§6) are **things to check**, not findings.
- Roles do not leak between sessions: log out (`POST /logout`) and confirm the login page before each new account; the role switcher is tested separately.
- Server-side checks (route opened by URL, Livewire method called directly) are done read-only where possible; a write is only attempted on a test record.

---

## 1. Test accounts (identified from the DB, not from names)

Roles live in `hchr_roles` / `hchr_model_has_roles` (`is_active` = the active role, see `User::myRole()`). Permissions come from `config/access-control.php` (`role_baselines`; HR roles get everything grantable; `administrator_roles` = `super-admin`, `HR-SYR`).

| Key | Account | Roles (active ★) | City | Notes |
|---|---|---|---|---|
| GM | fredy.youssef@hcsyria.org | general-manager ★ | Aleppo | 49 perms; read-only badges; no `attendance.manage`, no `leaves.manage-balances` |
| HRS | lana.abdo@hcsyria.org | **HR-SYR ★**, HR-ALP | Aleppo | Administrator (bypass). Also used to test the **role switcher** → HR-ALP (city-scoped) |
| HRC | lina.sayegh@hcsyria.org | **HR-LAT ★**, HR-TAR | Latakia | City HR. Switcher → HR-TAR. Same city as the managers below |
| MG1 | adonis.mowas@hcsyria.org | manager ★ | Latakia | Direct reports: celine.khoury, anna.jabbour, mirelle.saoud, issa.abdoush… |
| MG2 | hala.nseir@hcsyria.org | manager ★ | Latakia | Direct reports: maria.tojjo, miray.awikeh, fadi.mahfoud… |
| MG3 | rain.bchara@hcsyria.org | manager ★ | Tartus | Direct reports: bashar.wassouf, lina.bchara… |
| E1 | celine.khoury@hcsyria.org | employee | Latakia | Reports to MG1 |
| E2 | maria.tojjo@hcsyria.org | employee | Latakia | Reports to MG2 (same city as E1, different manager → horizontal IDOR) |
| E3 | magie.najjar@hcsyria.org | employee | Aleppo | Other city → cross-city IDOR target / HRC must not see |
| — | guest | none | — | Unauthenticated access to every route |

Not used unless you say so: the only `super-admin` account (rafi.louliki — your own account, not in the provided list).
Passwords: `password` as given (not verified from the DB; confirmed at first login).

---

## 2. Page inventory & checklist

Legend for each page: **M** = modals/dialogs/drawers, **W** = widgets/tables/forms/filters/tabs, **A** = actions to test, **Gate** = route middleware.
Component paths are under `app/Livewire/` and `resources/views/livewire/`.

### 2.1 Auth & application shell
| # | Page / route | Component | Gate | M / W / A | ☐ |
|---|---|---|---|---|---|
| P01 | Login, forgot, reset `/{loc}/login` … | `Auth/*` | guest | W: form, validation, throttle, locale | ☐ |
| P02 | App shell (every page) | `Layout/Sidebar, Header, BottomNav, BottomBarPicker, Breadcrumbs, GlobalSearch`, `Auth/RoleSwitcher`, `Notifications/Dropdown, Counter`, `Feedback/FeedbackForm`, theme toggle, “New” menu, assistant widget | auth | M: feedback form, sidebar dialog, search; A: switch role, switch locale EN/AR, theme, new-menu items per role, menu items per role (`app/Support/Navigation.php`) | ☐ |
| P03 | Dashboard `/` + `/dashboard/window/{tile}` | `Dashboard/Index, Hub, Header, AddToDashboard, Window` + 31 tiles (`DashboardLayout::WIDGETS`: late, decide, my-leaves, notes, team, news, calendar, upcoming-leaves, today-events, upcoming-events, pending-requests, checklist, badges, soon-ending-contracts, stats-department/job-level/contract-type/no-leaves/**salary**/personnel, who-is-out, next-holiday, leave-balance, birthdays, clock, glance, attention, request-leave, me, my-requests, employees) | auth | M: add-to-dashboard; A: add/hide/reorder tiles, open tile window, tile actions (decide leave, request leave); which tiles each role may add (salary tile!) | ☐ |
| P04 | Work inbox `/inbox` | `Inbox/Index` | auth (rows scoped per source) | A: act on rows, counters match | ☐ |
| P05 | Notifications `/notifications` | `Notifications/Index` | auth | A: mark read, open target, counter/badge update | ☐ |
| P06 | Settings: `/settings/password`, `/settings/app`, `/settings/notifications` | `Settings/*` | auth | W: password form validation; push devices | ☐ |
| P07 | AI assistant `/assistant` | `Assistant/Chat` | auth (404 when off) | only reads asker’s own record — check it cannot leak others | ☐ |

### 2.2 Employees
| # | Page / route | Component | Gate | M / W / A | ☐ |
|---|---|---|---|---|---|
| P08 | Employees list `/employees` | `Employee/EmployeeModule, EmployeeTable, EmployeeDrawer` | `employees.view` | W: table, search, filters, saved views, cards (`partials/card`), pagination, export; M: drawer (9 dialog triggers in `employee/drawer.blade.php`), row actions, index dialog | ☐ |
| P09 | Create employee `/employees/create` | `Employee/EmployeeCreate` (+ `AutosavesEmployeeDraft`) | `employees.create` | W: personal-info form, photo upload, draft autosave; validation | ☐ |
| P10 | Edit employee `/employees/{id}/edit` | `Employee/EmployeeEdit` + forms in `Forms/Employee/*` | `employees.update` | tabs: personal info, contact, education, experience, courses, relatives, attachments, badges, checklist, items, photo; M: proposal dialog, identity dialogs; A: save each section, upload/delete attachment | ☐ |
| P11 | Profile `/my-profile`, `/employees/{username}/show` | `Employee/EmployeeProfile` + `OnboardingJourneyPanel`, `OffboardingJourneyPanel`, `RenewalJourneyPanel`, `EmployeeCards/ManageDigitalId` | auth + `EmployeePolicy` | tabs per role; contract history; attachments download (`/employee-files/{media}/download`); avatar (`/employee-photo/{id}`); hover card `/people/{id}/card` | ☐ |
| P12 | Org chart `/org-chart` | `Position/OrgChart, ProfileDrawer, Directory` | `positions.view` | M: profile drawer (6 dialogs); search; colleague details per Directory tier | ☐ |
| P13 | Public digital ID `/ID/{card}` (+ signed photo) | `EmployeeCards/ShowDigitalId` | none (throttled) | invalid/revoked/disabled card → same 404 page | ☐ |

### 2.3 Contracts
| # | Page / route | Component | Gate | M / W / A | ☐ |
|---|---|---|---|---|---|
| P14 | Contracts list `/contracts` | `Contract/ContractsModule, ContractsTable, ContractDrawer, ContractRenewModal` | `contracts.view` (manager: direct reports only) | W: table/cards, filters, status chips; M: drawer (7 dialogs), renew modal, row actions; A: export PDF, terminate, renew | ☐ |
| P15 | Create / renew `/contracts/create`, `/contracts/{id}/renew` | `Contract/ContractCreate` (composer, `DrivesContractComposer`) | `contracts.create` | position dropdown via `Position::scopeInCity` + matching validation; capacity; pay modes; salary visibility | ☐ |
| P16 | Edit `/contracts/{id}/edit` | `Contract/ContractEdit` | `contracts.update` | pay/schedule locked after an applied amendment; hidden pay preserved | ☐ |
| P17 | Details `/contracts/{id}` | `Contract/ContractDetails, ContractAmendments` | `contracts.view` + policy | renders `asSigned()`; amendments list | ☐ |
| P18 | Amend `/contracts/{id}/amend`, `/amendments/{a}/edit`, export/preview/signed | `Contract/ContractAmend`, `ContractExportController`, `ContractAmendmentSignedController` | `contracts.amend` / `contracts.export` | draft → approve → apply; one open amendment; PDF gates (pay-blind roles refused) | ☐ |

### 2.4 Leave, calendar, holidays, attendance
| # | Page / route | Component | Gate | M / W / A | ☐ |
|---|---|---|---|---|---|
| P19 | My leaves `/leaves` | `Employee/Leave/MyLeaveRequestModule, MyLeaveRequestTable, LeaveRequestDialog, UpdateLeaveRequest, BirthdayHolidayCard` | `leaves.view-own` | M: request dialog (`leave/request-dialog`, `leave/form`), details modal, confirm-save; W: status tabs (Pending/Accepted/Rejected), leave-type cards, impact preview; A: create, edit, delete pending, birthday holiday choice | ☐ |
| P20 | All / team leaves `/leaves/all` | `AllLeaveRequestModule, AllLeaveRequestTable` | `leaves.view-all` | M: details, decision (approve/reject/object/review), create-for-employee, delete confirm; W: tabs, counts, filters; A: decide (`LeaveRequestPolicy::decide/review/object`) | ☐ |
| P21 | Leave balances `/leaves/balance` | `EmpLeaveBalanceModule, EmpLeaveBalanceTable`, `Forms/LeaveBalanceForm` | `leaves.manage-balances` | M: balance edit dialog; calculations (deserved, overtime_credit, amendment_offset) | ☐ |
| P22 | Team calendar `/team-calendar` | `Team/Calendar` | `leaves.view-own` (TeamCoverage scope) | who is away per role; month navigation | ☐ |
| P23 | Holidays `/holidays` (+ Basic settings tab) | `Basics/HolidayModule, HolidayTable`, `partials/holiday-dialog` | none on route (component checks) | M: holiday dialog; program managers vs national HR | ☐ |
| P24 | Timesheets `/attendance/timesheets` + `/print`, `/export/pdf`, `/export/xlsx`, `/signed/{month}` | `Attendance/Timesheets`, `AttendancePrintController`, `AttendanceExportController`, `AttendanceSignedController` | `attendance.view-own` + `Roster` | W: month picker, grouping (city→project/department), rows; M: 3 dialogs (day decision, etc.); A: decide day, confirm, approve, lock, reopen, signed upload; DRAFT marker | ☐ |
| P25 | Attendance upload `/attendance/upload` | `Attendance/Upload` | `attendance.manage` | upload .xls/.xlsx, device-number linking, locked month skips | ☐ |

### 2.5 Checklists / deliverables
| # | Page / route | Component | Gate | ☐ |
|---|---|---|---|---|
| P26 | My items `/checklist-items/all` | `ChecklistItems/AllItems` | `checklists.view-own` | ☐ |
| P27 | Pending delivers / returns `/checklist-items/pending-delivers`, `/pending-returns` | `PendingItems`, `PendingReturns` (+ counters) | `checklists.deliver` / `.receive` | ☐ |
| P28 | Done requests `/checklist-items/done-requests` | `DoneRequests, DoneRequestsTable` | `checklists.manage` | ☐ |
| P29 | Default deliverables `/default-deliverables` | `ProgramManagement/DefaultDeliverable/*` (1 modal) | `checklists.manage` | ☐ |

### 2.6 Organisation & reference data
| # | Page / route | Component | Gate | ☐ |
|---|---|---|---|---|
| P30 | Positions `/positions`, `/create`, `/{id}/edit` | `Position/PositionsModule, PositionTable, PositionCreate, PositionEdit`, `Forms/PositionForm` (1 modal) | `reference_page:positions` (hidden from employee, manager) / `positions.create/update` | ☐ |
| P31 | Centers `/centers`, `/create`, `/{id}` | `ProgramManagement/Center/*` (1 modal: weekend days) | `reference_page:centers` (hidden from employee) | ☐ |
| P32 | Programs `/programs`, `/create`, `/edit/{slug}`, `/{slug}` | `ProgramManagement/Program/*` | `reference_page:programs` (hidden from employee) | ☐ |
| P33 | Basic settings `/basic-settings?tab=` — cities, contract-types, leave-types, annual-leave-rules, termination-reasons, checklist-items, job-levels, departments, course-categories, holidays (+ legacy redirect URLs) | `Basics/BasicSettings` + 10 `*Module/*Table` (each with a `showModal` dialog) | `reference_page:project-basics` (hidden from employee, manager) | ☐ |

### 2.7 Communication & records
| # | Page / route | Component | Gate | ☐ |
|---|---|---|---|---|
| P34 | News `/news`, `/create`, `/{id}/edit`, `/{id}`, `/{id}/preview` | `News/*`, `Forms/NewsForm` (preview modal) | `news.view/create/update`; show = audience check | ☐ |
| P35 | Badges `/badges` | `Badges/BadgesModule, BadgesTable` (badgeDetails modal) | `badges.view` | ☐ |
| P36 | Message templates `/message-templates` | `Messages/TemplateManager` (1 modal) | `message-templates.manage` | ☐ |
| P37 | Mail lists `/mail-lists` | `MailLists/Index` (2 dialogs) | none on route | ☐ |
| P38 | Folders `/folders/{id?}`, `?scope=shared`, file preview/download | `Archives/ArchivedFilesModule`, `ArchiveFileController` | `archives.view` / `.download` | ☐ |

### 2.8 Reports & administration
| # | Page / route | Component | Gate | ☐ |
|---|---|---|---|---|
| P39 | Statistics `/statistics/general`, `/statistics/contract`, `/employee-statistics/{mode}` | `Statistics/*`, `Employee/EmployeeStatistics` | `statistics.view` | ☐ |
| P40 | Activity log `/activity-log` | `ActivityLog/*` | `activity-log.view` | ☐ |
| P41 | Access management `/access-management` | `AccessManagement/Index` (assigned-role-users modal) | `access-management.view` | ☐ |
| P42 | Feedback inbox `/feedback` | `Feedback/FeedbackIndex` (showFeedback modal) | `can:feedback.read` (IT only) | ☐ |
| P43 | UI sheet `/_ui` | `UiSheetController` | local/testing only | ☐ |

### 2.9 Recruitment (large module — see question in summary)
| # | Page / route | Component | Gate | ☐ |
|---|---|---|---|---|
| P44 | Requests `/recruitment/requests`, `/create`, `/{id}`, `/{id}/edit`, `/team`, `/direct-open` | `Recruitment/Requests/*` | `recruitment-requests.view/manage/direct-open` | ☐ |
| P45 | Pending approvals `/recruitment/approvals` | `Recruitment/Approvals/*` | `recruitment-requests.approve` + assigned approver | ☐ |
| P46 | Campaigns `/recruitment/campaigns`, `/create`, `/{id}`, job-post, application-form, invites, publishing | `Recruitment/Campaigns/*`, `JobPosts/Editor`, `ApplicationForms/Editor`, `PublicLinks/Manager` | campaigns/job-posts/forms perms | ☐ |
| P47 | Applicants `/recruitment/applicants`, `/{id}` | `Recruitment/Applicants/*` (bulk reject, tags, flags, review panel, notes) | `RecruitmentApplicantPolicy` | ☐ |
| P48 | Interviews `/recruitment/interviews`, `/{id}`, create/edit | `Recruitment/Interviews/*` (3 dialogs + evaluation) | policy / `schedule-interviews` | ☐ |
| P49 | Offers `/recruitment/offers`, `/{id}`, edit/revise/convert | `Recruitment/Offers/*` (3 dialogs) | `manage-offers`, `convert-hires` + `canViewSalary()` | ☐ |
| P50 | Indicator rules `/recruitment/indicator-rules` | `Recruitment/Indicators/Rules` | `recruitment-indicators.manage` (per user) | ☐ |
| P51 | Public careers `/careers/{token}` (+ submit, sign-in, invitation) | `PublicCareerController` | none (throttled) | ☐ |

---

## 3. Role × Page × Action matrix (template)

Expected access is pre-filled **from the code** (route middleware + `role_baselines` + `reference_pages_hidden_from` + `Navigation.php`). Execution fills *Actual* and flags any mismatch.
Cell values: **F** full · **S** scoped (own / reports / city) · **R** read-only · **—** no access (expect 403/404) · **?** to determine.

| Page | Guest | E1/E2/E3 employee | MG1–3 manager | HRC (HR-LAT) | HRS (HR-SYR) | GM |
|---|---|---|---|---|---|---|
| P03 Dashboard | — (→login) | S | S | S | F | S |
| P08 Employees list | — | — | — | S (city) | F | F? |
| P09/P10 Create / edit employee | — | — | — | S | F | F? |
| P11 Other employee profile | — | ? (directory tier) | ? (reports) | S | F | ? |
| P14 Contracts list | — | — | S (reports, R) | S | F | F? |
| P15/P16 Create / edit contract | — | — | — | S | F | ? |
| P18 Amend contract | — | — | — | S | F | — (no `contracts.amend`) |
| Contract PDF / pay figures | — | — | — | ? `canViewSalary` | ? | ? |
| P19 My leaves | — | F (own) | F (own) | F (own) | F (own) | F (own) |
| P20 All leaves | — | — | S (reports, decide) | S (city) | F | F? (decide) |
| P21 Leave balances | — | — | — | S | F | — |
| P22 Team calendar | — | S | S | S | F | ? |
| P24 Timesheets | — | own | own + reports | city | all | all |
| P24 Decide day / approve / lock | — | confirm own | decide/approve reports | all + lock | all + lock | ? |
| P25 Attendance upload | — | — | — | F | F | — |
| P26–P29 Checklists | — | own | own (+deliver if responsible) | F | F | manage |
| P30 Positions | — | — (hidden) | — (hidden) | F | F | R? |
| P31/P32 Centers / Programs | — | — (hidden) | R | F | F | R / programs F |
| P33 Basic settings | — | — | — | F | F | R |
| P34 News manage | — | — | create/update | F | F | create/update |
| P35 Badges | — | — | — | F | F | R |
| P36 Message templates | — | — | — | F | F | — |
| P39 Statistics | — | — | — | F | F | F |
| P40 Activity log | — | — | — | ? | F | — |
| P41 Access management | — | — | — | ? | F | — |
| P42 Feedback inbox | — | — | — | — | — (IT only) | — |
| P44–P49 Recruitment | — | — (P48/P49 by participation) | requests, approvals, endorse | F (minus never-granted) | F | requests/approve/endorse |

Per page, the report expands this into the requested table:

| Role | Access (menu / direct URL) | Can see (whose data, columns, tabs, buttons) | Can manage (each action tried) | Blocked (UI-only or server too) | Notes |
|---|---|---|---|---|---|

### How "server-side blocked" is checked
1. **Route:** open the URL directly as the role → expect 403/404, not the page.
2. **Livewire action:** in the browser console, call the method on a component the role *can* open (e.g. `Livewire.first().call('delete', id)` / `$wire.set(...)`) with an ID outside the role's scope → expect 403/validation, never a write. Guarded methods are listed in `config/access-control.php` (`livewire` section, enforced by `ActiveLivewirePermissionMiddleware`); unlisted public methods are the first candidates.
3. **IDOR:** swap IDs/usernames in URLs (`/employees/{username}/show`, `/contracts/{id}`, `/attendance/signed/{month}`, `/employee-files/{media}/download`, `/employee-photo/{id}`, `/people/{id}/card`, recruitment `{id}`s) between E1 ↔ E2 ↔ E3, and between cities for HRC.
4. **Role switcher:** HRS→HR-ALP and HRC→HR-TAR must narrow scope immediately, including in already-open tabs/Livewire requests (no stale permission).

---

## 4. Testing order

1. **Auth & shell** — P01, P02 (menus per role, role switcher, locale, theme), P03 dashboard, P04 inbox, P05/P06.
2. **Employee profiles** — P11 (my profile, other profiles), P08 list, P09 create, P10 edit, P12 org chart, P13 public ID.
3. **Contracts** — P14–P18 (incl. salary visibility and PDF gates).
4. **Attendance & leave** — P19 my leaves, P20 all/team leaves, P21 balances, P22 team calendar, P23 holidays, P24 timesheets, P25 upload.
5. **Requests & approvals** — leave decision chain end-to-end (E1 → MG1 → HRC), attendance month workflow (confirm → approve → lock → reopen), checklists P26–P29, inbox counters.
6. **Payroll / finance-related** — pay figures on contracts, amendments, badges, dashboard salary tile, statistics salary widget, offers (canViewSalary + blind roles).
7. **Reports** — P39 statistics, P40 activity log, exports (employees, attendance PDF/XLSX).
8. **Settings / admin** — P30–P33 reference data, P34–P38 comms & folders, P41 access management, P42 feedback.
9. **Recruitment** — P44–P51 (if in scope).

Within each page: guest → E1 → E2/E3 → MG1 (→ MG2/MG3 where scope matters) → HRC (+switch) → HRS (+switch) → GM. Each page done in **EN (LTR)** and spot-checked in **AR (RTL)**, light and dark.

---

## 5. Viewports

| Name | Size | Notes |
|---|---|---|
| Desktop L | 1440 × 900 | primary |
| Desktop S | 1024 × 768 | sidebar collapse, table width |
| Tablet | 768 × 1024 | |
| Mobile L | 390 × 844 | bottom nav, drawers, modals full-height, tables |
| Mobile S | 360 × 740 | worst case for long Arabic labels |

Per page/modal: horizontal scroll (`document.documentElement.scrollWidth > innerWidth`), cropped/overlapping text, modal scroll + reachable close/footer, touch targets ≥ 40px, focus outline, console errors, failed requests, slow responses (> 1s Livewire round-trip noted). Brand rules from CLAUDE.md/DESIGN.md (single red, status = icon + words + shape, shared components `<x-status>`, `<x-button>`, `<x-tag>`) are used as the consistency baseline.

---

## 6. Focus areas to verify (from reading the code — not findings yet)

- Leave statuses are only Pending / Accepted / Rejected (`app/Enums/LeaveRequestStatus.php`): how does an employee withdraw a request? What happens to an accepted leave later changed or deleted (balance restored?).
- Leave chain: `decide` vs `review` vs `object` in `LeaveRequestPolicy` — what if the approver is the requester, the employee has no manager, or the manager is in another city.
- `/holidays` and `/mail-lists` routes have no route middleware — confirm the component enforces the scope.
- `/employees/{username}/show`, `/employee-files/{media}/download`, `/employee-photo/{employee}` rely on policies, not route permissions — IDOR candidates.
- Users holding two roles (lana: HR-SYR + HR-ALP; lina: HR-LAT + HR-TAR): scope after switching, and in Livewire requests from a tab opened before the switch.
- Salary visibility: `User::canViewSalary()`, `salary.blind_roles`, dashboard `stats-salary` tile, `SalaryPerEmployeeStatistics`, contract PDF.
- Position dropdown vs validation must both use `Position::scopeInCity` (contract create/renew/edit).
- Attendance: approval withdrawn by new decisions/punches; locked month; overtime credit on lock and reversal on reopen.
- Leave across month/year boundaries, weekends from the center, holidays, half days / hours, overlapping requests, double-submit.

---

## 7. Test Data Log (filled during execution)

| # | When | Role / account | Page | Record type | ID | What was done | Undo |
|---|---|---|---|---|---|---|---|
| | | | | | | | |

## 8. Deliverables

- `HR_TEST_REPORT.md`, appended per page / page group: summary, role matrix, modal inventory, issues (`HR-###`, severity, category, page+role+viewport, steps, expected vs actual, evidence, suggested fix), what works well.
- Final summary: issues by severity × category, top 10, cross-page inconsistency patterns, permission gaps, grouped fix order.
- Stop after each page group for your review. No fixes until you say so.
