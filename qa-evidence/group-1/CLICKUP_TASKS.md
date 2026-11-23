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

---
---

# Task title

**QA Group 3 – Contracts: shift-hour validation, delete dialog scope, disabled button and phone list (HR-021, HR-023, HR-025, HR-026; HR-024 reviewed)**

**Status:** Done · **Priority:** High · **Tags:** QA, Validation, Permissions, UI, Mobile
**Commit:** _(fill in after commit)_
**Attachments:** the 4 screenshots and 6 text files in `qa-evidence/group-3/` (`*-before.*` / `*-after.*`)

---

# Description

## Summary
The QA review of Group 3 (contracts list, create, renew, edit, details, amendments, PDF) found 6 issues. This task fixes 4 of them. On closer testing, a fifth (HR-024) turned out to be intended behaviour. HR-022 (deleting a contract also deletes its applied amendments) is deliberately not part of this task.

Tested on `https://hc-hr.test` as city HR (lina.sayegh, HR-LAT), at 390px.

## Fixes

### ✅ HR-021 · First-shift working hours were never validated (High)
- **Before:** `ContractForm::validateTerms()` checked the second shift only. "21:00 to 17:00" or "09:00 to 01:00" (01:00 meant 1 PM) was saved and printed on the signed contract. Three active contracts already have inverted hours: **#153 (21:00→17:00), #163 (09:00→01:00), #273 (08:00→04:00)**.
- **After:** the same rule as the second shift. Both times or neither, `H:i`, and the end after the start. Not *required*: 75 older fixed-schedule contracts have no hours on file and must stay editable. The composer refuses it in the browser too, with the hint "Set the working hours: the end time must be after the start time (13:00 for 1 PM)." (EN/AR).
- **Files:** `app/Livewire/Forms/ContractForm.php`, `resources/js/contract-composer.js`, `resources/views/livewire/contract/composer/_screen.blade.php`, `resources/lang/{en,ar}/general.php`
- **Tests:** in `tests/Feature/ContractComposerScheduleTest.php`, `test_a_first_shift_that_ends_before_it_starts_is_rejected` and `test_a_flexible_schedule_needs_no_shift_hours`.
- **Evidence:** `HR-021-test-run-before.txt` (the old code let 09:00→01:00 through validation) / `-after.txt` (rejected on `form.work_hours_to`)
- **⚠ Follow-up for HR:** the three contracts above are **not** changed by this task; their correct hours are an HR decision. Until they're corrected, editing or amending them shows the new error.

### ✅ HR-023 · Disabled primary button showed as pale pink (Low)
- **Before:** every disabled `.hc-btn` got `opacity: 0.45`, so a red primary ("Save amendment") faded to pink. The brand rule says red is never tinted into a pale fill.
- **After:** a disabled primary uses the neutral disabled look (surface fill, ink-3 text, full opacity). This applies to every primary button in the app.
- **File:** `resources/css/components.css`
- **Evidence:** `HR-023-disabled-save-amendment-390-before.png` / `-after.png` and `.txt` (before: `rgb(190,31,36)` at 0.45; after: `rgb(230,231,232)` / `rgb(147,149,152)` at 1)

### ✅ HR-025 · Contracts list on phones: dates broken mid-value, mixed date formats, stacked chips (Low)
- **Before (390px):** card text broke inside a date ("2026-" / "12-31"). The five preset chips stacked one per line, pushing the first card below the fold. Dates appeared in three formats (table `01-01-2026`, drawer `2026-01-05`, cards ISO).
- **After:** the phone card and the drawer use `DD-MM-YYYY` like the table and the rest of the module, and each date stays on one line. On phones the preset chips are one row that scrolls sideways (no page scroll). Two full cards are now on the first screen.
- **Files:** `resources/views/livewire/contract/_card.blade.php`, `contract-drawer.blade.php`, `contracts-table.blade.php`
- **Evidence:** `HR-025-contracts-list-390-before.png` / `-after.png`

### ✅ HR-026 · Delete dialog opened for a contract the user may not delete (Low)
- **Before:** `ContractsTable::confirmDelete()` had no check. As HR-LAT, `confirmDelete(5)` (an Aleppo contract) opened the delete dialog. The delete itself was already refused.
- **After:** `confirmDelete` checks the same `delete` policy. The same applies to the basic-settings tables' `confirmDelete` (`project-basics.delete`) and the holidays table (`canChange`, the rule its delete already uses).
- **Files:** `app/Livewire/Contract/ContractsTable.php`, `app/Livewire/Basics/*Table.php` (8 tables + `HolidayTable.php`)
- **Evidence:** `HR-026-confirm-delete-other-city-before.txt` (HTTP 200, dialog opened) / `-after.txt` (HTTP 403, no dialog). An own-city contract still opens the dialog (checked, then cancelled).

### ℹ️ HR-024 · "Message shown twice": reviewed, no change needed
- The amendment page shows the weekly-hours error in the form-level summary **and** at the field. In the browser the summary sits at the top (in view) while the field's message is about 1,300px lower (out of view), so the user never sees both at once.
- The summary is designed to list exactly the errors whose field isn't on screen, each linking to its field. The original report counted both because the check didn't test what was in view.
- The same probably applies to HR-031 (leave dialog); to be re-checked in that group.

## Verification
- [x] Browser, 390px: the HR-023 button colours, the HR-025 list (chips in one row, dates whole, no horizontal page scroll), and HR-026 (Aleppo 403, own city opens the dialog).
- [x] 43 test files covering contracts, amendments, basic settings, holidays and identity components: only the 2 pre-existing `ContractAutoRenewalWorkflowTest::test_warning_command_…` failures.
- [x] Before/after evidence captured on the original code and on the fixed code (Chrome). Files restored and checked byte for byte afterwards.
- [x] Full test suite: 2,940 tests. The 2 new tests pass. The same 5 failures as before, all pre-existing (they fail without these changes too): `EmployeeToolsTest`, `BottomNavTest`, 2 × `ContractAutoRenewalWorkflowTest`, `BrandedEmailTest`.

## Not in this task
- [ ] **HR-022 (High):** deleting a contract with applied amendments deletes them too, against the "never delete an amendment in effect" rule. Left for a separate decision.
- [ ] **Data:** correct the hours of contracts #153, #163 and #273 (HR to confirm the real times).
