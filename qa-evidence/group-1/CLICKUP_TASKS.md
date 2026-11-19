# Task title

**QA Group 1 – Dashboard & app shell: mobile layout and link fixes (HR-001–006, HR-010–012)**

**Status:** Done · **Priority:** Medium · **Tags:** QA, UI, Mobile, RTL
**Commit:** `4f6ca5dc` 080-Dashboard mobile fixes (HR-001–006, 010–012)
**Attachments:** the 22 screenshots (`*-before.png` / `*-after.png`) and the 4 text files in `qa-evidence/group-1/`

---

# Description

## Summary
The QA review of Group 1 (login, app shell, dashboard) found 12 issues across every role. 9 are fixed in this task; 3 were left as they are by decision (listed at the end). Most were mobile layout problems at 390px and 360px. Two were link and redirect problems.

Tested with employee, manager, city HR, HR-SYR and general manager accounts, in English and Arabic (RTL), on `https://hc-hr.test`. Account in the screenshots: adonis.mowas (manager).

**No permission or security issues were found in this group:** every route hidden from a role is also refused on the server.

## Fixes

### ✅ HR-001 · Weather tile clipped on phones (Medium)
- **Before:** at 390px the temperature and city were hidden (53px tile for 134px of content). At 360px the condition showed as "P.".
- **After:** two equal rows: weather (condition and city on one side, temperature on the other), then clock, date and time. On narrow, short tiles the condition sits above the temperature.
- **File:** `resources/css/dashboard.css`
- **Screenshots:** `HR-001-weather-390-*`, `HR-001-weather-360-*`

### ✅ HR-002 · "My Leaves" card cut off its last line (Low)
- **Before:** the "TOTAL" line was cut (198px of content in 173px).
- **After:** the type sits under the number, and the columns stay side by side with wrapping lines. "Total" is hidden on short tiles because it already shows as "/7".
- **Files:** `my-leaves-card.blade.php`, `dashboard.css`
- **Screenshots:** `HR-002-my-leaves-390-*`

### ✅ HR-003 · Bottom-bar labels didn't fit (Low)
- **Before:** "Dashboard" showed as "Dashboarc" at 360px. Two-line labels ("Team calendar", "ملفي الشخصي") pushed their icons out of line.
- **After:** icons are aligned from the top, padding is smaller, and labels are 11px under 380px.
- **File:** `layout/bottom-nav.blade.php`
- **Screenshots:** `HR-003-bottom-nav-360-*`, `HR-003-bottom-nav-390-ar-*`

### ✅ HR-004 · ~100px empty strip above the first card (Low)
- **Before:** the minimised-cards strip was always drawn, even when empty.
- **After:** on phones it folds away when empty (shown while editing, or when a card is minimised). Desktop is unchanged.
- **Files:** `dashboard/index.blade.php`, `dashboard.css`
- **Screenshots:** `HR-004-dock-gap-390-*`

### ✅ HR-005 · Menu backdrop didn't cover the bottom bar (Low)
- **Before:** with the menu open, the bottom bar stayed sharp and tappable.
- **After:** the backdrop is `z-[35]`, between the bottom bar (30) and the menu (40).
- **File:** `layout/sidebar.blade.php`
- **Screenshots:** `HR-005-menu-backdrop-390-*`

### ✅ HR-006 · "Needs you" card scrolled inside itself (Low)
- **Before:** 366px of content in a 265px card, so there was an inner scroll. In Arabic the request type ran under the "started" tag.
- **After (narrow tiles only):**
  - The duplicate count line is read by screen readers only.
  - "View all" keeps its 44px target without adding header height.
  - The type and the tag share one line, and a long type ends in "…".
  - Padding is tighter, so the content fits exactly (265/265).
- **Files:** `dashboard.css`, `decisions-card.blade.php`
- **Screenshots:** `HR-006-needs-you-390-en-*`, `HR-006-needs-you-390-ar-*`

### ✅ HR-010 · Notification links had no locale (Low)
- **Before:** links stored as `/employees/x/show` worked only through an extra redirect.
- **After:** the viewer's locale is added when a link is rendered (this also fixes notifications already stored). `/ID/…` and `/careers/…` stay as they are, and outside hosts are still refused.
- **File:** `app/Livewire/Concerns/HandlesNotificationActions.php`
- **Evidence:** `HR-010-notification-links-before.txt` → `-after.txt`

### ✅ HR-011 · Print link without a month silently went back (Low)
- **Before:** `/en/attendance/print` with no `?month` returned to the dashboard with no message.
- **After:** it opens the Timesheets page's default month. This covers PDF and Excel too.
- **Files:** `app/Services/Attendance/PrintSelection.php`, plus a new test in `tests/Feature/AttendanceTest.php`
- **Evidence:** `HR-011-print-no-month-*` (.png and .txt)

### ✅ HR-012 · Two red buttons in dashboard edit mode (Low)
- **Before:** "Add" and "Done" were both solid red, because `<x-button icon="plus">` always renders the red add-square.
- **After:** "Add" uses the `layout` icon. The toolbar is now Reset (quiet), Add (outline) and Done (the only primary).
- **File:** `dashboard/index.blade.php`
- **Screenshots:** `HR-012-edit-toolbar-390-*`

## Verification
- [x] Checked in the browser at 390px and 360px, English and Arabic.
- [x] Dashboard, identity-component, notification, bottom-nav and attendance tests pass (192 + 315). The only failure, `BottomNavTest::test_each_role_gets_the_destinations_its_permissions_open`, fails without these changes too.
- [x] Front-end assets rebuilt (`npm run build`).

## Not done (by decision)
- [ ] **HR-007:** two menu items are both named "Contracts" (the list and the statistics page).
- [ ] **HR-008:** the general manager holds `contracts.export` but can't use it (export needs pay visibility).
- [ ] **HR-009:** the manager role in the DB is missing `recruitment-campaigns.view` and `recruitment-vacancies.view`, which the config grants it.
- [ ] **HR-047:** the welcome notification has no name ("Let's welcome  to the team"). `NewEmployeeWelcome` uses the non-existent `name_en`/`name_ar`.

---
---

# Task title

**QA Group 2 – Employee pages: permission holes, validation messages and org chart on phones (HR-014, HR-016, HR-017, HR-018, HR-020)**

**Status:** Done · **Priority:** High · **Tags:** QA, Security, Permissions, Validation, Mobile
**Commit:** _(fill in after commit)_
**Attachments:** the 8 screenshots and 10 text files in `qa-evidence/group-2/` (`*-before.*` / `*-after.*`)

---

# Description

## Summary
The QA review of Group 2 (employees list, create and edit, profile, org chart, public ID card) found 8 issues. This task fixes 5 of them: two permission holes that could delete data across cities, the create-employee validation messages, and the org chart on phones. HR-013 (hard delete of employees), HR-015 (people holding two positions) and HR-019 (guessable public card links) are not part of this task.

Tested on `https://hc-hr.test` as city HR (lina.sayegh, HR-LAT) and as the general manager (fredy.youssef), at 390px and 1024px.

## Fixes

### ✅ HR-014 · Family records of any employee in any city could be deleted (High, IDOR)
- **Before:** on an employee's edit page, `removeRelative(index, id)` deleted the relative with that ID through an unscoped `Relative::find($id)`. Whoever could edit one employee could delete any relative in the system. This was proven: HR-LAT, from a Latakia employee's page, deleted a test relative of an Aleppo employee.
- **After:** the ID is looked up among the edited employee's own relatives (`$this->employee->relatives()->find($id)`), like education, courses, experience and attachments already were. The same hole was closed in `ItemForm` (an unused form with an unscoped delete and `updateOrCreate`).
- **Files:** `app/Livewire/Forms/Employee/RelativeForm.php`, `app/Livewire/Forms/Employee/ItemForm.php`
- **Test:** new `tests/Feature/EmployeeRelativeScopeTest.php` (a foreign relative survives; one's own is still removed). It fails on the old code and passes on the new.
- **Evidence:** `HR-014-test-run-before.txt` / `-after.txt`

### ✅ HR-020 · Actions reached through an event skipped the permission check (High)
- **Before:** the permission middleware checked only `calls[].method`. A method reached through a Livewire event (a confirm dialog's callback, `$dispatchTo`) arrives as `__dispatch`, so delete, toggle and export handlers ran for anyone who could open the page. This was proven: the general manager, who can only view Basic Settings, exported leave types and deleted a course category.
- **After, two layers:**
  1. The middleware resolves `__dispatch` to the listener method and applies the same permission map.
  2. Every exposed handler checks its own permission (`authorizeActivePermission`), using the permission the map gives it: the delete and export handlers of the 9 basic-settings tables, the city on/off toggle, and the employees, contracts, positions and leave-balances exports.
- **Files:** `app/Http/Middleware/ActiveLivewirePermissionMiddleware.php`; `app/Livewire/Basics/{AnnualLeaveRule,ChecklistItem,City,ContractType,CourseCategory,Department,JobLevel,LeaveType,TerminationReason}Table.php`; `app/Livewire/Contract/ContractsTable.php`; `app/Livewire/Employee/EmployeeTable.php`; `app/Livewire/Employee/Leave/EmpLeaveBalanceTable.php`; `app/Livewire/Position/PositionTable.php`
- **Test:** new `tests/Feature/EventListenerPermissionTest.php` (a viewer gets 403 on delete and export through an event; a holder of the permission still deletes through the confirm event).
- **Evidence:** `HR-020-gm-event-calls-before.txt` (HTTP 200: export downloaded, record deleted) and `-after.txt` (HTTP 403, record kept)

### ✅ HR-016 · Create-employee errors used column names and one wrong message (Medium)
- **Before:** errors read "The first name ar field is required." and "The City Name field is required." (the field is labelled "Current Place of Residence"). The optional, empty Nationality field gave "The nationality must be an integer.". The Username error didn't say where the field is. The labels were "First name" next to "Last Name".
- **After:** every field is named the way the form labels it, e.g. "The First Name (AR) field is required." and "The Current Place of Residence field is required.". Nationality is `nullable|integer`. The Username message says which section to open ("Employment Info"). "First Name" is Title Case like the other labels. EN and AR both.
- **Files:** `app/Livewire/Forms/Employee/PersonalInfoForm.php`, `resources/lang/en/employees.php`, `resources/lang/ar/employees.php`, `resources/lang/en/general.php`
- **Evidence:** `HR-016-017-validation-before.txt` / `-after.txt`, `HR-016-messages-top-390-*.png`

### ✅ HR-017 · After a failed save, the focus landed halfway down the form (Low)
- **Before:** after an empty Save, the form scrolled to and focused **National Number** (first in the server's rule order), with the required name fields above it off screen. The original report said "focus does not move". That was wrong, an artifact of testing by script, and it is corrected here.
- **After:** the focus goes to the error that comes **first on the page** (First Name (AR)). Errors on another tab still lead to the error summary.
- **File:** `resources/js/feedback.js` (`errorSummary.focusFirstError`)
- **Evidence:** `HR-017-focus-after-save-390-before.png` / `-after.png`, and the "Focused after the failed save" line in `HR-016-017-validation-*.txt`

### ✅ HR-018 · Org chart unusable on phones (Low)
- **Before (390px):** the header, its subtitle, a 2×2 block of counters and three rows of controls filled the whole first screen, so no chart was visible. The search placeholder was cut ("Search name or positi"). Card text was 8.8px (job title) and about 5.5px (centre / program).
- **After (phones only, desktop unchanged):** no subtitle; the four counters sit in one row; zoom, fit, expand/collapse and export fold behind a "View options" button; search is full width. The first card is now on the first screen. Card text has floors: job title 11px, centre, city tag and badge 10px (proportional above that, on every screen size).
- **Files:** `resources/views/livewire/position/org-chart.blade.php`, `resources/css/app.css` (`.org-id-card__*`), `resources/lang/{en,ar}/general.php` (`org-view-options`)
- **Evidence:** `HR-018-org-chart-first-screen-390-*.png`, `HR-018-org-card-390-*.png`, `HR-018-card-font-sizes-*.txt`

## Verification
- [x] Browser, 390px and 1024px: messages, focus, org chart (collapsed and opened), desktop unchanged.
- [x] HR-020 through the real browser path: the general manager's `delete` and `exportExcel` events now return 403, and the test record is kept.
- [x] The middleware resolves each guarded component's listeners (checked with tinker, including a renamed one: `openAnnualLeaveRuleEdit` → `edit`).
- [x] Full test suite: 2,938 tests. The 4 new tests pass. 5 failures, all **pre-existing**: they fail on the original code too (checked by running them without these changes). They are `EmployeeToolsTest::test_my_items_…`, `BottomNavTest::test_each_role_gets_the_destinations_…`, 2 × `ContractAutoRenewalWorkflowTest::test_warning_command_…` and `BrandedEmailTest::test_an_arabic_only_notification_…`.
- [x] Before/after evidence captured on the original code and on the fixed code (Chrome, real clicks). The HR-020 "before" run really deleted a throwaway category; the "after" run was refused and the row kept. Both test rows have been removed.

## Not in this task (still open from Group 2)
- [ ] **HR-013 (Critical):** deleting an employee is permanent and cascades to contracts, leave, attendance and the login account.
- [ ] **HR-015 (Medium):** people holding two positions show only one in the org chart drawer.
- [ ] **HR-019 (Low):** public ID card links are guessable (sequential employee ID).
