# A4: Bilingual and RTL experience in core journeys

Task A4 (R-18) in [ux-prompts-v3.md](ux-prompts-v3.md). Worked on branch `a4` (base `1d53c1f1`, A3-R).
Status: **implemented and committed (`bb71b430`), awaiting review** (QA engineer + technical lead) **and the Arabic content reviewer**. The human content review (step 4) is still **pending**, so A4 is **not** done.

## 1. Prerequisites

| Input | State | Effect on A4 |
|---|---|---|
| X1 | Baseline measured ([evidence/x1](evidence/x1/summary.md)). | None. A4 changes wording and markup only. No query is added except the dashboard contract-type chart, which now also reads `name_ar` (same query, one more column). |
| X2 | Instrument ready, **0 participant sessions** ([usability-baseline.md §10](usability-baseline.md)). | **Decided 2026-10-21 (§4.1): option (b)**, merge and disclose. There is **no pre-change usability baseline for the A4 delta**; X2-R must say so (AC-X2-R-01). Labels changed here (see §2) need a version note in the X2 scripts. |
| A5 | Implemented and merged, screen-reader pass pending ([a5-implementation.md](a5-implementation.md)). | A5 deliberately left "Showing …" and the pagination sentence to A4 (done, §2.5). Dialog focus and naming are A5's and were not changed. |

No upstream decision (DEC-01…10) is needed for A4. No route, policy, permission or salary change. One schema change after the 2026-10-21 decisions: nullable `users.locale` for the mail language (§4.2, plan in §5). Stored values (names, leave-type names, notification payloads, enum ids) are shown as stored, never translated.

## 2. What changed

### 2.1 Inventory method

1. **Key audit** (scratch script, run against the booted app): every `__()`, `trans()`, `trans_choice()`, `@lang()` literal key in `app/` and `resources/views/` resolved in `en` and `ar`, plus EN/AR parity of every group file. Results in §3.1.
2. **Rendered audit** in the browser, `/ar/` and `/en/`, on the synthetic A4 copy: visible text nodes and `placeholder` / `title` / `aria-label` carrying Latin letters, each classified as *legitimate* (person/company names, emails, phones, codes such as `HR-ALP`, `PDF`, `Telegram`, stored data) or *untranslated UI*. Results in §3.2.
3. A line-based scan of Blade literals was tried first and dropped: Blade tags span lines, so it returned ~2 900 false positives (classes, Alpine code).

### 2.2 Raw keys shown to users (bugs, both languages)

These keys did not exist in **either** language, so the key itself appeared on screen:

| Key | Where | Fix |
|---|---|---|
| `validation.custom.position_id.max_reached` | Contract save when the position is full (`ContractObserver`, 3 places) | Uses the existing `contracts.errors.position-full`. |
| `general.na`, `general.n_a` | Contract details / create / renew, news table | One key `general.na` (N/A · غير متوفر). |
| `general.view`, `general.update` | Centers and programs tables, leave edit title | Added. |
| `general.add_note` | Checklist pending note placeholder | Uses the existing `general.add-note`. |
| `auth.login`, `auth.register` | Guest block of the sidebar profile card | Added. |
| `auth.throttle` (AR only) | Login / forgot password lockout | Added. |

Arabic was missing for `general.name_en`, `name_ar`, `items_from_checklist`, `back_to_list`, `delivered`, `received`, `no_notifications`. English was missing for `general.general-info`, `employee-dashboard`, `hours`, `updating-statistics`, `badges-management`. All added.

### 2.3 Arabic plural forms

The old plural keys had two segments ordered to suit English (`'day' => 'يوم|أيام'`). Laravel picks Arabic forms by six categories (0 · 1 · 2 · 3–10 · 11–99 · 100+), so Arabic read **«1 أيام»**, **«2 يوم»**, **«11 يوم»**, and years of service read **«5 الشهر»** (`general.month` is a label, not a unit).

New keys with all six forms: `general.count.{days,hours,minutes,months,years}`; `leaves.duration.*` moved to the same six-form layout. `:count` may carry the bold figure. Used by:

| Caller | Shows |
|---|---|
| `LeaveBalance::getFormattedLabel` (deserved / used / remaining on the leave cards) | «يومان»، «3 أيام»، «11 يومًا» |
| `LeaveRequest` duration | same, hours and minutes too |
| `formatDuration()` helper (dashboard pending leave) | same |
| `Employee::years_of_service_formatted` (employees table, profile) | «سنة واحدة 3 أشهر», "1 Year 3 Months" (was "1 Year 3 Month") |
| `AnnualLeaveRuleTable` | fractions keep the number with the plain unit |

`contracts.*-count` keep their `{n}` / `[a,b]` interval form on purpose: `contract-composer.js`'s `choice()` parses that form in the browser.

### 2.4 Earlier A4 work in this working tree (same branch, before this session)

| Area | Change |
|---|---|
| Enums | `LeaveRequestStatus`, `LeaveType` gender/marital read `leaves.status.*` etc. One status vocabulary: قيد المراجعة / مقبول / مرفوض. Spelling fixes in `EducationType` (أساسي, PhD) and `KinshipType` (الزوج/الزوجة). |
| Dashboard | Greeting, calendar month name (`translatedFormat`), weekday headers from `dashboard.weekdays-min` with the full name in `<abbr title>`, prev/next buttons named, birthdays/news "in N days" (`dashboard.in-days`), contract-type and salary-per chart labels, CEO title, new-note default, empty states. |
| Leaves | `leaves.php` group: units, statuses, duration wording, server validation messages (working hours, max hours/days). Confirmation dialogs moved to `components/leave/{request,balance}-confirmation` with translated wording and the six-form durations. Validation attribute names for leave and balance forms. "Select Employee…" placeholder (A3-R §6) translated. Dates in the tables isolated with `<bdi dir="ltr">`. |
| Employees | `employees.php` group (table id, statistics quarters, validation for phone / national number / city scope / manager city). Names in tables wrapped in `<bdi>`, emails `dir="ltr"`, join date in the page language. Status column as text + colour, filter options in the page language. |
| Contracts | `contracts.php` group: server errors, validation attribute names, pager, status words (Terminated / Expired / Draft distinct), month and hour counts. Status and auto-renew columns as words, not a bare dot. |
| Tables / pagination | Vendor pagination views (`livewire-table`, `livewire`, `pagination`) use `pagination.showing` / `navigation` / `go_to_page`; Arabic «السابق / التالي» without `&laquo;` (read literally in `aria-label`); `-ml-px` → `-ms-px`, chevrons `rtl:rotate-180`. Table bar strings in `ar.json`. |
| Profile / tabs | Employee profile and tab views use keys; digital ID strings added. |

### 2.5 This session

- §2.2 and §2.3 above.
- **Select2's own messages** ("No results found", "Searching…", "Remove item"…) were English in Arabic. They now come from `general.select2` through a `<meta name="hc-select2-i18n">` in `partials/head.blade.php`, set as select2's default language in `resources/js/app.js`. No inline script, so the CSP nonce is not involved.
- **Sidebar group headings** were inline `locale === 'ar' ? … : …` ternaries (X2 dry-run §5). Now `general.nav-group.*`.
- `ar.json`: role switcher ("Current:", "No other roles available."), "Saved.", position-form city errors, activity-log columns, course categories, error pages 402/429, verify-email / confirm-password / register, mail template lines, meta description.
- **Mail**: the vendor mail layout sets `lang` / `dir` from the locale and the theme aligns with `text-align: start`, so Arabic mail reads right to left.
- **After the browser review** (§3.2): birthday event text (`TodayEventsCard`); role names shown through `role_label()` (stored names unchanged: `employee` → موظف, `HR-ALP` → «الموارد البشرية · ALP»), in the employees table, header, profile card and role switcher; `alt="user"` on avatars → `alt=""` (the name is next to it); "Open sidebar" and "Breadcrumb" names; «مراجَع من الـ HR» → «راجعته الموارد البشرية»; one "no results" wording («لا توجد نتائج»).
- **Compound durations** join with «و» in Arabic through `join_counts()` and `general.count.and` («34 يومًا و3 ساعات و12 دقيقة», «5 سنوات و9 أشهر»). English is unchanged ("5 Years 9 Months").
- **Leave form labels were swapped in the UI**: the leave-type list was labelled «النوع» and the day/hour choice «نوع الإجازة». Now the list is «نوع الإجازة» and the choice «وحدة الإجازة» (`leaves.unit.legend`), in both the employee and HR forms, with matching validation attribute names.
- **Empty leave submit** reported only «القيمة المحددة لـ نوع الإجازة غير صالحة»: `confirmSave()` built the confirmation (which needs a balance) before validating. It now validates first, so each missing field gets its own «حقل … مطلوب.» under it. The save path still validates again in `store()`/`update()`.
- **Logout** from `/ar/…` landed on the English sign-in page (the session holding the locale is cleared). It now redirects to the localized home of the current locale.
- **Notifications** (Arabic wording only; payload shape `title/body {en, ar}` + `url` unchanged, so stored notifications stay readable): consistent «طلب إجازتك من نوع», new-request notice «طلب إجازة (سنوية) من …» instead of a masculine verb and a missing «إجازة» (EN "Annual leave request from …"), balance amounts through `general.count.days`, corrected spelling/plurals in contract-expiry, auto-renew summary, center and welcome notices.

## 3. Acceptance evidence

### 3.1 AC-A4-02: keys and rendered validation

Key audit after the fixes (§2.1 method 1):

| Check | Result |
|---|---|
| Group-file parity EN ↔ AR | 1 gap left: `general.team1`, Arabic-only contract body text (see §4). |
| Literal keys missing in a language | 0 (dynamic keys such as `leaves.unit.{type}` checked by hand). |
| JSON literals without Arabic | Only the unused Bootstrap pagination views. |

Arabic plural examples (`trans_choice`, locale `ar`):

| n | `general.count.days` | `general.count.years` |
|---|---|---|
| 0 | 0 يوم | 0 سنة |
| 1 | يوم واحد | سنة واحدة |
| 2 | يومان | سنتان |
| 3 / 10 | 3 أيام / 10 أيام | 3 سنوات / 10 سنوات |
| 11 / 99 | 11 يومًا / 99 يومًا | 11 سنة |
| 100 / 101 | 100 يوم / 101 يوم | 100 سنة |
| 111 | 111 يومًا | 111 سنة |

EN: 1 Day, 2 Days, 0 Days.

Tests (`tests/Feature/A4BilingualTest.php`, 9 tests, 82 assertions), meaningful rather than markup mirrors:

| Test | Proves |
|---|---|
| Key parity EN ↔ AR | Guard only (not proof of correct wording). Allow-list: `general.team1`. |
| Six Arabic plural forms, English singular/plural | `general.count.*` at 0, 1, 2, 3, 11, 100. |
| `LeaveBalance`, `LeaveRequest`, years of service in AR/EN | يوم واحد / يومان / 3 أيام / 12 يومًا / يومان و3 ساعات / ساعة واحدة و30 دقيقة / سنتان و3 أشهر; "2 Years 3 Months". |
| Empty leave request in Arabic | Exact Arabic "required" sentence with the Arabic field name; no raw `validation.` keys or `form.` paths. |
| Full position on the web path | `contracts.errors.position-full` text in AR and EN; fails with the old `max_reached` key. |
| `LeaveRequestStatus` AR/EN | One status vocabulary. |

`Auth\AuthenticationTest::test_users_can_logout` now expects the localized home URL (behaviour change in §2.5, intended).

Full suite, PHP 8.5, SQLite `:memory:`, 2026-10-21: **622 tests, 618 passed, 4 failed, 0 errors** (after the §4.1 decisions; 618/614 before them). The 4 failures are baseline defects listed in [test-baseline.md](test-baseline.md): `ContractAutoRenewalWorkflowTest` (duplicate warning, B6), `ContractCreatePerformanceTest`, `ContractPayClauseTest` (PDF), `ContractRenewalTest` (`<script>` assertion). None is caused by A4.

Rendered validation in the browser (AR, `staff`/`manager` persona, empty *إضافة جديد* leave form): «حقل نوع الإجازة مطلوب.»، «حقل تاريخ البداية مطلوب.»، «حقل تاريخ النهاية مطلوب.»، «حقل السبب مطلوب.», each under its field.

### 3.2 AC-A4-01: no unexplained English in Arabic journeys

Browser: the synthetic A4 copy (`127.0.0.1:8393`, `X2_HOME=C:/laragon/tmp/hc-hr-a4`, `*@example.test` personas, no real data), built assets from this working tree. Pages walked in `/ar/` with HR, manager and staff personas, `/en/` spot-checked: dashboard (all cards), my leaves (cards, form, validation), all leaves (table, filters, pagination, empty state, decision dialog), leave balances, employees list (pagination, filter, empty search), an employee profile, contracts list and the contract composer, notifications dropdown and page. Each page's visible text and `placeholder`/`title`/`aria-label` with Latin letters was listed and classified.

Found and fixed (§2.5): birthday text, raw role names, `alt="user"`, "Open sidebar", "Breadcrumb", the mixed-script «الـ HR» header, swapped leave-form labels, the empty-submit message, the missing «و» in compound durations, logout locale, two "no results" wordings, Select2 messages.

Latin text left on purpose (legitimate): person and company names as stored, emails, the EN/AR switcher, city codes in role labels (ALP), department/program names and position titles stored in Latin (`Finance`, `HC`, `DISC`, `LEAP`, `ICDL`), currency codes ("800 USD"), "QR", "Telegram", synthetic notes and checklist item names (single-language `name` column), and stored reject reasons.

Confirmed in Arabic: Select2 no-match «لا توجد نتائج» and search box «بحث»; pagination «عرض 1 إلى 15 من أصل 84»; filter options and status/yes-no badges as words; calendar «أكتوبر 2026» with Monday-first headers ن ث ر خ ج س ح titled by full day name; countdowns «اليوم / غدًا / بعد 3 أيام»; plurals on screen «يوم واحد، يومان، 3 أيام، 12 يومًا، 12 شهرًا، 5 سنوات و9 أشهر»; notifications page and empty state «لا توجد إشعارات»; English `/en/leaves/all` LTR with no stray Arabic.

### 3.3 AC-A4-03: long mixed-script values, keyboard order

| Check | Result |
|---|---|
| Names in tables | `<bdi>`; a Latin name inside the Arabic table keeps its word order. |
| Dates | Tables: `<bdi dir="ltr">` numeric `d-m-Y` (e.g. 20-10-2026). Range «04-11-2026 ← 06-11-2026» reads start → end in RTL. Join date in the page language («13 يناير 2021»). |
| Emails, phones | `dir="ltr"`; not reordered. |
| Role labels | «الموارد البشرية · ALP» reads correctly with the Latin code at the end. |
| Logo | `001_HC.png` and `hope-center-icon.svg` carry no transform; page `dir="rtl" lang="ar"`. |
| Keyboard order | Contract composer: Tab moves right → left within a row, then down, with no backward jumps. Leave dialog order was checked by A5 (AC-A5-01) and is unchanged except labels. |

Not checked: 200 % text zoom, phone widths, and a screen reader on the Arabic pages (A5's screen-reader pass is still pending). Native date inputs show the browser's own `dd/mm/yyyy` placeholder, which follows the browser language, not the page.

## 4. Decisions and open items

### 4.1 Decisions taken (product owner, 2026-10-21)

| Question | Decision | Implemented |
|---|---|---|
| Arabic office hours in the contract wording (composer preview and PDF) printed «9:00 AM». | Use «9:00 ص» / «5:00 م» in the Arabic column. The English column keeps "9:00 AM". | `schedule-clause.blade.php` (`$to12HourAr`) and `contract-composer.js` (`to12Hour(value, true)`), so preview and PDF stay identical. Applies to every contract rendered from now on, including re-prints of existing contracts (the clause is rendered from stored hours, not stored text). Test: `ContractScheduleClauseTest` checks «9:00 ص», «5:00 م», «6:00 م», «8:00 م». |
| X2 baseline before merging A4. | Merge and disclose: no pre-change baseline for this delta. | Recorded in §1. |
| Language of the contract renewal / expiry emails (were always Arabic). | Each recipient's own language. | §4.2 and §5. |
| Numeric dates in tables (`20-10-2026`). | Keep numeric, day first. | No change. |

### 4.2 Recipient language for mail

- `users.locale` (new, nullable `string(5)`), migration `2026_10_21_000000_add_locale_to_users_table`.
- `RememberUserLocale` (web group, after `EnsureAccountIsActive`) stores the current interface language when it differs from the stored one. One query-builder update per language switch: no model events, no activity-log row, no `updated_at` change. Unsupported locales are ignored.
- `User` implements `HasLocalePreference`; Laravel renders every mail to that user in `preferredLocale()`. Null (a user not seen since deploy) falls back to the current locale: the request's locale for mail sent during a request, `APP_LOCALE` (`ar` in production) for scheduled commands. That is today's behaviour for these emails.
- `ContractsAutoRenewedSummary` and `ContractsExpiringAutoRenewWarning` pick subject, line and employee names in that language, so greeting and body match.
- Tests: `tests/Feature/A4UserLocaleTest.php` (stores and follows a switch; writes nothing else; ignores an unsupported locale; one EN and one AR recipient each get the subject in their own language).

### 4.3 Left open

| Item | Why it is not changed here | Owner |
|---|---|---|
| `general.text*`, `general.team1`: Arabic-only contract body strings, identical in `en/general.php` or missing there. | The contract body is bilingual by design and rendered from `contracts/details/*`; these are legacy keys. | A6 cleanup. |
| Latin names inside stored notification text are not isolated with Unicode marks. | Would change stored and emailed text. The dropdown can use `dir="auto"` per line if the reviewer sees reordering. | D3 / B6. |
| Role switcher with several roles was not walked (the personas hold one role each); the labels go through the same `role_label()`. | Needs a multi-role persona. | QA |
| Auth register / verify-email screens: translated in `ar.json` but not walked (registration is not a core journey). | Out of A4's core-journey scope. | — |
| Human review of natural Arabic, long names and date ambiguity (task step 4). | Needs the Arabic content reviewer. Dates in tables stay `d-m-Y` with `dir="ltr"` (decided, §4.1). | Arabic content reviewer. |

## 5. Rollout and recovery

Mostly UI wording, markup and lang files: revert the A4 commits to roll back. Stored notifications keep the same shape; old rows render as before.

One schema change (§4.2), with its compatibility and recovery plan:

| Step | What happens |
|---|---|
| Deploy | Run the migration with the code. The column is nullable with no backfill; every existing user starts at null, which behaves exactly as before (mail in the app default language). |
| Data written | Only `users.locale`, only for signed-in users, only on a language change. No other column or table is touched. |
| Rollback | Revert the code **and** run `php artisan migrate:rollback --step=1` (drops the column). Code without the column fails on every authenticated request (the middleware writes it), so never roll back the migration alone; reverting only the code is safe and leaves an unused column. |
| Loss on rollback | Only the remembered languages; users re-acquire them on their next visit after a redeploy. |
| Not done | No live migration was run. Production deploy needs separate authorization. |

## 6. Handoff

- **G-A**: AC evidence in §3, open items in §4.
- **D3** (contextual help): use the keys in `leaves.php`, `contracts.php`, `employees.php`, `dashboard.php` and `general.count.*` for any new help text; plural strings must use the six Arabic forms.
- **X2**: labels changed (sidebar groups, status words, pagination sentence); version the scripts' expected-path wording.
