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
