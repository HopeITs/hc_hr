# برومبتات تنفيذ خطة UX الموحّدة (24 بنداً) — HC-HR

> المرجع: `HC-HR-UX-Improvement-Plan-AR.pdf` (15 تشرين الأول 2026)
> يحلّ محل [ux-prompts.md](ux-prompts.md) (نسخة الـ 15 بنداً).

## طريقة الاستخدام

- **برومبت واحد = جلسة Claude Code جديدة = فرع واحد.** لا تجمع برومبتين في جلسة.
- انسخ ما داخل الصندوق كما هو. البرومبتات بالإنجليزية لأنها أدق مع الكود؛ الرد يأتي بالعربية.
- كل برومبت يقول للنموذج: اقرأ «القواعد المشتركة» من هذا الملف. لا تحذفها.
- البرومبتات الكبيرة تبدأ بـ **خطة وتنتظر موافقتك**. راجع الخطة قبل أن تقول «نفّذ».
- بعد كل برومبت شغّل: `/code-review` ثم (للبنود الحساسة) `/security-review`.
- الترتيب: المرحلة أ كاملة أولاً. **لا تبدأ ب أو ج قبل أن تنجح بوابة المرحلة أ.**

## حالة البنود الآن (من الكود، 16 تشرين الأول)

| البند | الحالة | الدليل |
| --- | --- | --- |
| 1 التحميل | ⚠️ جزئي — قُصّر إلى 300ms لكن الفيديو ما زال يظهر عند **كل** انتقال | `87e31bfa` |
| 2 التنقل | ✅ منفّذ — يبقى تحقق فقط | `8769e98a`, `dd62fa25` |
| 3 الرسائل | ⚠️ جزئي — Toast وتأكيد موحّدان؛ ينقص: ملخص أخطاء قابل للنقر، تحذير مغادرة | `5feb02aa` |
| 4 الإجازة | ❌ — الحقل select2 + خيار «اختيار» بلا `value` وعليه `@selected` | `leave/form.blade.php:11-19` |
| 9 الموافقات | ملاحظة: **لا توجد وحدة توظيف** في الكود. المصادر الفعلية: الإجازات + العهد | `app/Livewire/ChecklistItems` |
| 19 الإتاحة | ❌ مؤكد — `radio-input` يعطي كل زر `id="btn"`؛ `modal/dialog` بلا `role="dialog"` | `components/forms/radio-input.blade.php:2` |
| 24 الصلاحيات | ❌ مؤكد — فحص `Gate` في درج الملف **مُعلّق**؛ مسار البطاقة الرقمية يبحث بـ `username` | `ProfileDrawer.php:38-46`, `routes/web.php:33` |
| 22 العقود | ❌ مؤكد — `App\Support\ArabicText` حُذف في `3a0e5c49` و`pay-clause.blade.php:11` ما زال يستخدمه | قد يُسقط طباعة العقد فعلياً |

### نتيجة الاختبارات الآن: 516 ناجح · 13 فاشل

| المجال | الاختبارات الفاشلة | البرومبت |
| --- | --- | --- |
| الصلاحيات والهوية | `AuthorizationRegressionTest` (درج الملف 200 بدل 403)، `SecurityAuthorizationTest` + 2 × `PrivateAvatarAccessTest` (البطاقة الرقمية)، `LivewireMutationCoverageTest` (6 إجراءات غير مصنفة) | A2 |
| العقود والطباعة | `ContractPayClauseTest` (ArabicText)، 4 × `ContractPdfRendererTest` (`FileUrlNotAllowed` — Browsershot 5.4.0)، `ContractCreatePerformanceTest`، `ContractRenewalTest` | A6 |
| الإشعارات | `ContractAutoRenewalWorkflowTest` (تحذير الانتهاء: 2 بدل 1) | B6 |

> ⚠️ البند 24: التعطيلان تمّا **عن قصد** (`21e73581` «fix show profile from orgn chart»، `af2bba8b` «digital id … username»).
> لذلك البرومبت A2 يطلب **قرار سياسة منك** قبل أي تعديل، لا «إصلاحاً» أعمى.

---

## القواعد المشتركة (يقرؤها كل برومبت)

```text
SHARED RULES — apply to every task from docs/ux/ux-prompts-v2.md

Project contract
- CLAUDE.md is binding. Re-read the sections relevant to the files you touch.
- Stack: Laravel 13, Livewire 4 (+ Volt, Flux), Alpine, Tailwind 4, Vite 8. PHP 8.5 CLI.
- Reuse before adding: WithFeedback::toast()/confirm() (app/Livewire/Concerns/WithFeedback.php),
  <x-toasts/>, <x-confirm-dialog/>, x-forms.* inputs, x-center-empty-state, LivewireTable,
  ScopesToAuthorizedCity, AuthorizesActivePermissions, LeaveBalanceService, ChecklistWorkflow.
- Brand: --color-primary #c2151c and its ramp from resources/css/app.css. No new colours, no new fonts.

Security (never trade this for UX)
- City scoping goes through Position::scopeInCity() / ScopesToAuthorizedCity. Same scope for the
  dropdown, the validation and the query.
- Every new public Livewire method is an HTTP endpoint: authorize inside it, and classify it in
  config/access-control.php (livewire or livewire_exempt) — LivewireMutationCoverageTest enforces this.
- Salary figures only through User::canViewSalary(); never send hidden figures to the browser.
- Roles: role:HR expands to HR-*; use User::activeRoleIs() / @activerole, not raw role names.

Bilingual + RTL
- Every visible string goes through __() with keys added to BOTH resources/lang/en and resources/lang/ar.
- Use logical utilities (ms-/me-/ps-/pe-/start-/end-), never left/right. Check dir="rtl".
- Translatable columns use transColumn('name'); numbers/emails/Latin names inside Arabic get dir="ltr" or <bdi>.

Livewire
- Anything that must survive wire:navigate is navigate-safe (see commits 8769e98a, dd62fa25 for the pattern).
- Eager-load in query(); no N+1. Use #[Lazy] + placeholder() for slow cards.
- In Livewire 4 tests a ModelNotFoundException is a 404: use ->assertNotFound().

Workflow
1. Read the code first and state what you found in 3–6 bullets before changing anything.
2. If the task says "plan first", stop after the plan and wait for "go".
3. Small commits, one concern each, imperative subject line in the style of `git log`.
4. Tests: add Feature/Livewire tests for the behaviour you change. Run ./vendor/bin/pint and
   php artisan test. Report the pass/fail count before and after; never hide or skip a failing test,
   and never edit a test only to make it pass — say why the expectation was wrong.
5. Verify in the browser (/run or webapp-testing) in EN and AR, desktop and 375px, and say what you checked.
6. End with: what changed, what you did NOT do, open questions.
```

---

## المرحلة أ — الثقة والأساس (البنود 1، 4، 18، 19، 22، 24)

**بوابة الانتقال:** السيناريوهات الحرجة تنجح، وكل إخفاق آلي إمّا مُصلح أو مفسَّر.

### A1 — فرز إخفاقات الاختبارات (خط الأساس)

> ابدأ بهذا. هو ما يجعل باقي البرومبتات قابلة للقياس.

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: a trustworthy test baseline before any UX work.

Context: the UX review got 57 failures on its first run and 13 after forcing the test locale to
English. On 2026-10-16, with APP_LOCALE=en in .env: 516 passed, 13 failed (security: 5, contracts/PDF: 8
incl. the expiry-warning duplicate). Nothing in phpunit.xml or tests/TestCase.php pins the locale, so
a developer with APP_LOCALE=ar gets ~50 extra failures.

Do:
1. Pin the test locale: APP_LOCALE=en in phpunit.xml and, if needed, app()->setLocale('en') in
   TestCase::setUp(). Tests that check Arabic set it explicitly.
2. Run the full suite. For EVERY failure write one row: test, cause, class —
   (a) app bug, (b) wrong/outdated test expectation, (c) environment/config (Chrome, node, sqlite…).
   Include the key lines of evidence. Do not fix anything yet.
3. Group them by the plan's areas: profile & public identity, contracts & PDF, notifications,
   action coverage (LivewireMutationCoverageTest), other.
4. Stop and show me the table. Then fix only class (c) and the locale pinning in this branch;
   class (a) and (b) go to their own prompts (A2, A5, B6).

Done when: the suite result is reproducible on any machine, and I have a classified table of the rest.
```

### A2 — الصلاحيات والهوية الرقمية (البند 24) · قرار أولاً

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first. This task is security-critical.

Goal: unauthorized access is refused, and the digital ID behaves the way we decide — proven by tests.

Context (verified in code):
- app/Livewire/Position/ProfileDrawer.php: the Gate::denies('view', $employee) checks in openProfile()
  and getEmployeeProperty() are commented out (commit 21e73581 "fix show profile from orgn chart").
  openProfile(int $id) is a public endpoint, so any logged-in user can load any employee across cities.
  A cross-city test currently gets 200 instead of 403.
- routes/web.php:33: Route::get('/ID/{username}') with ->where('token', ...) — the constraint names a
  parameter that no longer exists, so it does nothing. ShowDigitalId::mount() looks up by username
  (commit af2bba8b). Tests expect a 64-char token. Usernames are guessable → the public card is
  enumerable. DigitalIdManager exists in app/Services.
- LivewireMutationCoverageTest reports unclassified public actions.

PLAN FIRST — do not change code until I choose:
1. Find out WHY the drawer check was disabled: which org-chart flow broke (e.g. a manager in another
   city, an employee viewing colleagues)? Show the EmployeePolicy::view rules and the failing case.
2. Offer 2–3 options, e.g. (a) restore the Gate as is; (b) a separate, narrower "directory" view
   (name, photo, position, work contact only) allowed org-wide, with full details still behind
   EmployeePolicy::view; (c) other. Say what each exposes.
3. Digital ID: check how the QR/printed link is generated today and whether cards already in
   circulation encode the username URL. Options: token only; token + temporary username redirect;
   keep username (and accept enumeration). Include disable + rotate behaviour.
4. List the unclassified Livewire actions with what each does and a proposed classification.

After I choose: implement, add tests (cross-city 403 on open AND on re-render after a role switch,
disabled card 404, rotated token invalidates the old link, every action classified), run the suite.

Done when: the related tests pass for the chosen policy and no access path is left only "hidden in the UI".
```

### A3 — طلب الإجازة: تشخيص الاختيار + أثر الطلب (البند 4)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: the employee sees what a leave request costs before submitting, and their choices stay selected.

Part 1 — diagnose (use the diagnosing-bugs approach; reproduce before fixing):
In Arabic, choosing "سنوية" in the leave type select falls back to "اختيار..".
Leads, not conclusions — confirm or rule out each:
- resources/views/livewire/employee/leave/form.blade.php:11-19 — the placeholder <option> has no
  value attribute (its value is its text) and carries @selected(...) on every render.
- x-forms.select2-input (resources/views/components/forms/select2-input.blade.php): @entangle +
  wire:ignore + a $watch that compares select.val() (string) with the entangled value (int);
  wire:model.live re-renders the modal on every change; wire:ignore.self on the modal.
- Whether it also happens in EN, on the alternative_employee select, and in the 18 other
  select2-input usages. If the root cause is in the shared component, fix it there and test one more form.
Write a failing test or a browser reproduction first, then fix.

Part 2 — show the impact inside the form:
1. Next to the selected type: available balance, requested amount (days or hours), balance after.
   Reuse LeaveBalanceService for the working-day/holiday/hour calculation — do not duplicate the rule in JS.
2. Explain the count in one line (e.g. "5 working days, excluding Friday, Saturday and 1 holiday").
3. Inline, linked to the field: end before start, overlap with own requests, insufficient balance (by how much).
4. Show the approval path (who decides next) from the existing workflow.
5. Translate the remaining hard-coded strings ("Leave Type", label="Start Date"…). Cancel closes the
   modal without a page reload. Unique ids for the day/hour radios.
Server-side validation stays the source of truth.

Tests: the reproduction, the calculation (weekend, holiday, hourly), insufficient-balance message.
Done when: the choice sticks in AR and EN, the summary updates instantly, and an over-balance request says why.
```

### A4 — تدقيق عربي/إنجليزي كامل (البند 18)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: no unintended English in an Arabic task, and both locales offer the same meaning and functions.

Do:
1. Inventory, don't guess: write a small throwaway script (scratchpad, not committed) that lists
   (a) literal text in Blade views not wrapped in __()/@lang/trans_choice, (b) label="..." /
   placeholder="..." literals, (c) keys present in resources/lang/en/*.php but missing in ar (and the reverse),
   (d) toast/validation messages built from literals in PHP.
2. Third-party UI: ramonrietdijk/livewire-tables strings (search, filters, pagination, "no results"),
   date/time pickers (day and month names, first day of week), select2 ("No results found",
   "Searching…"), Chart.js labels, role names (HR-SYR → localized label), enum labels
   (resources/lang/vendor/laravelEnum has only en).
3. Consistency: one Arabic term per status (pending/approved/rejected/active/ended), Arabic plural
   forms via trans_choice (1, 2, 3–10, 11+), one date format per locale.
4. Mixed content: wrap numbers, emails, phone numbers and Latin names inside Arabic text in <bdi> or
   dir="ltr"; check long names don't break cards; the logo must NOT be mirrored in RTL.
5. Show me the inventory with counts per area before translating. Then fix by area, one commit each.

Tests: extend AccessibilityTest::test_accessibility_strings_are_translated_in_every_locale into a
general "every en key exists in ar" test.
Done when: the inventory script finds nothing for the main flows (dashboard, leave, employees,
contracts, notifications) and the AR screens read naturally.
```

### A5 — الإتاحة: النوافذ والعناصر (البند 19)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: a whole task can be done by keyboard, every control has a name, every error is tied to its field.

Verified in code:
- resources/views/components/forms/radio-input.blade.php defaults every radio to id="btn" → duplicate
  ids and labels pointing at the wrong input.
- resources/views/components/modal/dialog.blade.php / modal.blade.php have no role="dialog",
  aria-modal or aria-labelledby (confirm-dialog.blade.php already does it right — use it as the reference).

Do:
1. Modals: one shared implementation with role="dialog", aria-modal="true", aria-labelledby pointing
   at the title, focus moved in on open, focus trapped (Alpine x-trap is available with Alpine's focus
   plugin — check whether it is installed), Esc closes, focus returns to the opener. Fix the page
   scrollbar showing over the open modal (body scroll lock).
2. Radio/checkbox: derive a unique id from name+value (or Str::uuid) when none is passed.
3. Icon-only buttons (table show/edit/delete/decision, header bell, sidebar toggle) get aria-label
   from translation keys.
4. Touch targets ≥ 44px on mobile for row actions and header buttons.
5. Check contrast of text on the brand ramp, 200% text zoom, and prefers-reduced-motion for the
   loader, toasts and skeletons.
6. Status is never colour-only (badges keep a text label or icon).

Tests: extend tests/Feature/AccessibilityTest.php (unique radio ids, dialog attributes, icon buttons named).
Done when: create a leave request and delete a note using only the keyboard, in AR and EN.
```

### A6 — العقود من المسودة إلى الطباعة (البند 22)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first. Pay figures are security-critical (CLAUDE.md).

Goal: the review screen and the printed contract match the contract data, and a failed export is
understandable and retryable without duplicating a contract.

Context (verified):
- app/Support/ArabicText.php was deleted in 3a0e5c49 ("add browsershot instead of domPDF") but
  resources/views/components/contract/pay-clause.blade.php:11 still does `use App\Support\ArabicText as AR`
  (also check schedule-clause and livewire/contract/details/*/pages/*). ContractPayClauseTest fails with
  "Class App\Support\ArabicText not found" when forPdf is true — the real export path may be broken.
- 4 × ContractPdfRendererTest fail with Spatie\Browsershot\Exceptions\FileUrlNotAllowed
  ("<h1>x</h1> is not a valid URL") on Browsershot 5.4.0; see app/Support/InspectableBrowsershot.php.
- ContractCreatePerformanceTest (three sections / locked single city) and ContractRenewalTest
  (malformed query string) fail on markup assertions.
The PDF pipeline rules are in CLAUDE.md (savePdf + disableCaptureURLS; never parse large stdout).

Do:
1. Run php artisan contracts:export-doctor and the contract tests. ArabicText: Chrome shapes Arabic
   itself, so the likely fix is to drop the glyph-shaping calls in the PDF branch rather than restore
   the class — confirm by exporting an Arabic contract. Browsershot: is this a test written against an
   older API, or does production also pass HTML where a URL is expected? Report each root cause before fixing.
2. Classify each contract test failure (app bug vs outdated expectation), using the A1 table if it exists.
3. Export UX: a clear translated error with a "Try again" action (through WithFeedback::toast), no
   duplicate contract or duplicate draft on retry, a loading state on the export button.
4. Keep what works: the three sections, the summary and the completeness counter in ContractCreate.
   Add short help text for the City → Program → Position → vacancies dependency and for draft and
   auto-renew status.
5. Test the Arabic and English documents for one annual and one service contract.

Done when: contract tests pass (or each remaining failure is explained), and a renewal + print works in both languages.
```

### A7 — إنهاء الانتظار المصطنع (البند 1)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: no deliberate delay; the click reacts instantly and the page is never covered once ready.

Context: commit 87e31bfa cut the overlay to 300ms + 250ms fade, but the full-screen <video>
(resources/views/components/layouts/app.blade.php ~line 36-43, assets/GIF/hr-website-2.mp4) still plays
on every full load AND every wire:navigate. config/livewire.php already shows the navigate progress bar.

Do:
1. Remove the overlay from wire:navigate entirely; rely on the thin brand-red progress bar.
2. On a full page load, show nothing unless loading actually takes long (e.g. only after 400ms), and
   never after the page is interactive.
3. Optional: a skippable welcome on the very first visit only (per user or per browser), behind one flag.
   Ask me before adding it.
4. Respect prefers-reduced-motion.

Done when: navigating dashboard → employees → contracts shows only the progress bar, and nothing
covers content that is already rendered. Measure before/after with the browser's performance panel and report it.
```

---

## المرحلة ب — تحسين المهام (البنود 2، 3، 5، 16، 17، 20)

**بوابة الانتقال:** المهام الأساسية تكتمل بالعربية والإنجليزية وعلى الهاتف دون عوائق معروفة.

### B1 — تحقّق التنقل (البند 2) · تدقيق فقط

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: prove wire:navigate (commits 8769e98a, dd62fa25) keeps context and breaks nothing.

Do, in the browser (webapp-testing / Playwright), EN and AR:
1. Back/forward/refresh and deep links on: dashboard, employees table (with search + page 3),
   contracts (search persistence from 7faa289a), employee profile, leaves, calendar card, org chart,
   statistics charts.
2. After each navigation: tables, select2, date pickers, charts, Swiper, tour, tags-input still work;
   no duplicated listeners (click once → one request in the network tab); console has no errors.
3. List remaining links that do a full reload and say whether each must (download, locale, external).
Fix only what the audit finds, one commit per cause, with the reproduction in the commit body.
```

### B2 — الرسائل: الجولة الثانية (البند 3)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: the user knows what was saved, what wasn't, and how to fix it — without re-entering data.

Already done (5feb02aa): one toast, one confirm dialog, Undo on note delete, the top error list only
for errors not visible on screen. Build on it, don't replace it.

Do:
1. Error summary at the top of long forms (employee, contract, leave): each item is a link that
   focuses the field and opens its tab. Inputs keep aria-describedby to their error (see AccessibilityTest).
2. Unsaved-changes guard on the employee and contract edit forms: warn on wire:navigate away, on
   closing the modal and on beforeunload — only when something changed.
3. A failed save keeps every entered value (check file uploads too) and says what failed.
4. Offer Undo (WithFeedback::toast(undo: ...)) where the action is truly reversible — list candidates
   and ask me; use the confirm dialog where it isn't.
5. Double-submit protection on every primary save button (wire:loading.attr="disabled").

Tests: guard triggers only when dirty; failed save keeps data. Done when: break a contract form on
purpose, fix it from the summary links, save — without retyping anything.
```

### B3 — حالات التحميل والفراغ (البند 5)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: every wait is explained, and every empty list offers the next step the user is allowed to take.

Do:
1. Skeleton components (line, card, table rows) in brand neutrals; shimmer off under reduced motion.
2. Dashboard cards in app/Livewire/Dashboard that run their own queries become #[Lazy] with a
   placeholder() skeleton.
3. LivewireTable tables: a skeleton or dimmed state while searching/filtering/paginating.
4. A local spinner on the button that triggered an action (wire:loading wire:target), not a page cover.
5. Two different empty states: "nothing yet" (with an action, e.g. "Request leave") vs "no results for
   this search" (with "Clear filters"). Extend x-center-empty-state (title, text, optional action) and
   show the action only if the user has the permission.
6. "Couldn't load — Try again" for lazy cards that fail.
Apply to: my leaves, all leaves, employees, contracts, checklist items, notifications, dashboard.
Done when: throttled to "Slow 4G", the dashboard paints instantly and fills in; each listed page has the right empty state.
```

### B4 — جداول أخف وعروض محفوظة (البند 16)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: common tasks don't need a wide scrolling table, and coming back from details keeps search and position.

PLAN FIRST: for EmployeeTable and ContractsTable (and the leave tables) list current columns and
propose the default set (name, position, city, status, actions) vs details. Wait for approval.

Then:
1. Default columns trimmed; other columns available from the column picker that LivewireTable already
   offers (check what ramonrietdijk/livewire-tables supports before building anything).
2. A side drawer for row details (reuse existing detail views/components), opened without leaving the table.
3. Visible filters with "Clear all".
4. Persist search, sort, filters and page across navigation — generalise the pattern from 7faa289a
   (contracts) instead of inventing a new one.
5. Saved views as quick tabs: "Active", "Ending in 30 days", "On leave today" (scoped by city).
6. Sticky first column on narrow screens.
Tests: persistence, views respect city scope. Done when: open an employee from page 3 of a search, go back, you're on page 3 with the same search.
```

### B5 — تسجيل موظف تدريجي ومسودة (البند 17)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: the user knows what is needed now and what can wait, and loses nothing between sections.

Context: the employee form (app/Livewire/Employee/EmployeeCreate.php, EmployeeEdit.php, form objects
in app/Livewire/Forms/Employee) has ~9 tabs, most disabled until the first save. A draft-contract
behaviour already exists (tests/Feature/EmployeeCreateDraftContractTest.php).

PLAN FIRST: which fields are truly required to create the record (by validation and by policy),
which tabs depend on the saved id and why. Propose the steps. Wait for approval.

Then:
1. Step 1 asks only the minimum; after saving, continue to Contact, Identity, etc.
2. A progress indicator ("3 of 7 sections complete") instead of a row of disabled tabs; a locked
   section says why and when it opens.
3. A draft employee is not active and cannot get an active contract until requirements are met —
   reuse the existing draft rules, don't add a second draft concept.
4. Moving between sections never drops unsaved input (warn or autosave — ask me which).
Tests: minimum create, locked sections, a draft can't be activated early. Done when: an HR user can stop after step 1 and continue later.
```

### B6 — إشعارات دون تكرار (البند 20)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: no unexplained duplicates; every notification opens the right record and shows whether it was handled.

Context: a test of the contract-expiry warning got 2 notifications instead of 1.
SendContractExpirationWarnings runs daily at 08:00 (routes/console.php); notifications live in
app/Notifications; HandlesNotificationActions and NotificationNavigationTest exist.

Do:
1. Reproduce the duplicate in a test and find the cause (recipient counted twice through two roles?
   command run twice? both a per-contract and a summary notification?). Report before fixing.
2. Idempotency: the same warning for the same contract and recipient at most once per day.
3. Group similar alerts ("4 contracts end this week") and mark them handled when the contract is renewed/ended.
4. Every notification has: status, required action, date, and a link to the exact record (check each class).
5. Separate "needs action" from news/info in the dropdown and the notifications page.
Tests: no duplicate on double run, grouped alert, link target per notification type.
```

---

## المرحلة ج — تنظيم التشغيل (البنود 6، 7، 8، 9، 10، 12)

**بوابة الانتقال:** اختبار مهام مع ممثلين عن الموظفين والمديرين والموارد البشرية.

### C1 — القائمة حسب المهام والدور (البند 6)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: every role reaches its frequent tasks without passing through settings or modules it doesn't use.

PLAN FIRST: map every item in resources/views/livewire/layout/sidebar.blade.php (with its permission
check) into: "My space" (profile, my requests, my documents), "My team" (manager), "HR operations"
(employees, contracts, leaves, reports, archives…), and a separate "Settings" page for reference
tables (cities, contract types, leave types, annual rules, levels, departments, termination reasons,
checklist items, course categories). Wait for approval.

Rules: navigation only — every route and permission check stays exactly as is. The active role and
city stay visible (RoleSwitcher). Show only allowed sections. Update data-tour targets and
resources/js/product-tour.js; keep the collapsed-sidebar mode.
Tests: per role, visible items = items it can open. Done when: no route became unreachable and the tour still runs.
```

### C2 — بحث شامل Ctrl+K + زر «جديد» (البندان 7 و8)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first. Search results are security-critical.

Goal: find the right employee fast and start a frequent task from any page.

Do:
1. One registry (PHP) of Pages and Actions, each with its permission — the same checks as the sidebar.
2. Palette: Ctrl/Cmd+K and a visible header search. Groups: Actions, Pages, Employees, Contracts.
   Employees match Arabic and English names (transColumn, the search macro) and show city + position to
   tell similar names apart. Results are filtered by role AND city scope in the query itself
   (ScopesToAuthorizedCity / Position::scopeInCity) — never filtered after fetching.
3. Keyboard: arrows, Enter, Esc, focus trap; ARIA combobox/listbox; open with wire:navigate.
4. A "New" button in the header from the same registry (request leave, add employee, create contract…),
   different per active role; after finishing, return to the page the user started from.
Tests: HR-ALP cannot find a Damascus employee; an employee sees only self-service actions; debounce + limit.
Done when: finding a known employee takes under 15 seconds by keyboard in both languages.
```

### C3 — صندوق موافقات موحّد (البند 9)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first. Authorization is security-critical.

Goal: every pending decision of the current user in one place, with a clear count.

Context: there is NO recruitment module in this codebase. Real sources today: leave requests
(app/Livewire/Employee/Leave/AllLeaveRequest*, UpdateLeaveRequest, LeaveNotificationService) and
checklist deliveries/returns (app/Livewire/ChecklistItems, ChecklistWorkflow, the Pending*Counter components).

PLAN FIRST: for each source list the model, who may decide (policy/permission + city scope), the
existing decision method and the notifications it fires. Propose an ApprovalSource interface that
reuses each module's own decision logic. Wait for approval.

Then: an "Approvals" page + sidebar/header count; rows show requester, type, duration, context;
Approve in one click, Reject with an inline required reason; decision history; bulk approve only where
the source allows it; confirm via WithFeedback::confirm. The old screens keep working.
Tests per source: who sees what, approve, reject with reason, bulk, notifications still fire.
```

### C4 — لوحة تحكم لكل دور (البند 10)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: from the first screen, each user can tell the most important thing they need to do.

PLAN FIRST: read resources/views/livewire/dashboard/index.blade.php and app/Livewire/Dashboard/*.
Propose the cards and their order per active role. Wait for approval. Target:
- Employee: my balance (+ Request leave), my requests and status, my documents, upcoming holidays.
- Manager: needs my action (from C3 if built), who is off today / next 7 days, team coverage.
- HR: contracts ending soon (SoonEndingContractsCard), incomplete profiles, overdue tasks, then KPIs.
- News and personal notes move below the work.
Optionally: let the user reorder/hide a few cards (store per user). Ask me before adding.
Rules: city scope and permissions on every figure; salary only via canViewSalary(); lazy cards (B3).
Tests: each role sees its cards and not others'. Done when: switching role in the header changes the dashboard.
```

### C5 — تقويم الفريق والتغطية (البند 12)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: a manager can judge coverage, and an employee sees the impact of their dates before submitting.

Do:
1. Team calendar page: day / week / month; filters for city, department/team and request status;
   weekends and public holidays shaded; approved solid, pending outlined (brand tones + text, not colour only).
   Start from the existing CalendarCard / UpcomingLeavesCard queries.
2. An overlap service method reused by the leave form (A3): "2 teammates are off on these days".
   Ask me whether the warning is advisory or blocking per policy, and say which in the UI.
3. Never show the reason of a private leave to users who are not allowed to see it.
Rules: one query per range, no N+1; city + team scope. Tests: scoping, overlap, private reasons hidden.
```

---

## المرحلة د — التوسع والتحسين (البنود 11، 13، 14، 21)

**بوابة الانتقال:** تحسن مثبت بالقياس، مع استقرار الصلاحيات وعدم تراجع المهام القائمة.

### D1 — ملف موظف موحد + خط زمني (البند 11)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: see the employee's status and history, and edit one section in place without losing your position
or exposing restricted data.

PLAN FIRST: read EmployeeProfile, the edit form tabs and the visibility tests
(EmployeeProfileRecordsVisibilityTest, EmployeeProfileBadgesVisibilityTest, EmployeeProfileLeaveStatusTest).
Propose the layout and which sections become inline-editable. Wait for approval.

Then: a header (avatar via getFirstMediaUrl('avatar'), position, city, status incl. the on-leave badge,
current contract); sections each with their own Edit/Save using the existing Form objects; a timeline
(hire, contract changes/renewals, approved leaves, badges, checklist deliveries) where restricted
entries show as "restricted" for users who can't see them; a "profile completeness" hint with what is missing.
Keep the old edit route until I remove it. Tests: per-section permissions; saving one section doesn't touch others.
```

### D2 — خدمة ذاتية للجوال أولاً (البند 13)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: core self-service tasks work at 375px and in landscape without page-level horizontal scroll or clipped elements.

PLAN FIRST: audit at 375px (portrait + landscape) with screenshots: dashboard, my leaves, request leave,
my items, my profile, notifications, the employee form. List what breaks. Wait for approval.

Then: below md a short bottom bar (Home, Requests, Inbox if allowed, Profile) with safe-area padding;
tables → stacked cards on those pages; full-width fields and a sticky submit bar; modals → full-screen
sheets; no stack of disabled tabs at the top of the employee form; touch targets ≥ 44px.
Desktop must not change. PWA (manifest + icons, no offline) only after this is stable — separate commit,
and the UI says which actions need a connection.
```

### D3 — مساعدة داخل السياق (البند 14)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: the user understands the current step without leaving the form, and can skip or replay the tour.

Do:
1. Keep the existing tours (resources/js/product-tour.js, ProductTourTest) optional; add a "Replay tour" entry.
2. Short help text (x-field-caption or a tooltip) on hard fields and decisions: leave type/hours,
   contract type, auto-renew, position dependency. Ask me for the wording list before writing it.
3. An in-app Help panel per page, sourced from the user guide in docs/, searchable, EN + AR.
4. An in-app feedback form that records the page, role and locale automatically (store in DB, super-admin list).
   Uploads only through SecureImageUploadService.
External links (Google Doc/Form) stay as an extra reference, never a requirement.
```

### D4 — رحلات توظيف وتسليم ومغادرة (البند 21) · تصميم فقط

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: a design document, not code. This item is deferred until phases A–C are done.

Write docs/ux/journeys.md: for hire, renewal and exit — the steps, owner, due date and status of each
step, which existing data is reused (employee, contract, checklist items via ChecklistWorkflow,
archives, deactivation commands), what is automated recommendation vs human decision, and what the
smallest first version would be. List the open policy questions for HR. Stop there.
```

---

## عبر كل المراحل (البندان 15 و23)

### X1 — أداء فعلي على اتصال بطيء (البند 23) · قياس أولاً

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first. Use the performance-optimization skill.

Goal: the UI stays responsive during requests, and a lost connection never duplicates data.

1. Measure first, no numbers without measurement: for dashboard, employees table, contract create,
   leave request — server time, query count (Debugbar/Telescope or DB::listen in a test), payload
   size, time to first content on "Slow 4G". Report a table. Wait for approval of the fix list.
2. Then: N+1 and duplicate queries, lazy cards and images (avatars), charts loaded on demand,
   smaller Livewire payloads (#[Computed], no large public arrays), idempotent saves on retry.
3. Network failure: a clear state and a safe "Try again" (Livewire request failure hook + toast).
Re-measure and report before/after. tests/Feature/Performance holds existing guards — extend them.
```

### X2 — قياس التحسن بمهام حقيقية (البند 15)

```text
Read "SHARED RULES" in docs/ux/ux-prompts-v2.md first.

Goal: decisions backed by a measurable difference. Targets are proposals, not current results.

Do:
1. docs/ux/usability-test.md: 4 tasks (submit a leave request, find an employee, review a request,
   create a contract draft), a script per task, success criteria, participants covering roles, cities
   and both languages, the SUS questionnaire in EN and AR, a one-question post-task rating.
2. Lightweight in-app timing (own table, user id + role only, no content): leave form opened→submitted,
   approval decided, palette used. A super-admin report: medians, completion, errors.
3. Proposed targets: leave request < 1 minute without help; find an employee < 15 s; mobile and
   keyboard tasks with no clipping or lost focus; SUS improves on the baseline.
Record the baseline BEFORE phase B starts.
```

---

## برومبت المراجعة (بعد أي برومبت)

```text
Review this branch against docs/ux/ux-prompts-v2.md — the task "<ID>" and its SHARED RULES.
Check: (1) does it meet the task's "Done when"; (2) security — city scope, policies, new public
Livewire methods classified, salary visibility; (3) both locales and RTL; (4) tests added and the
suite result before/after; (5) anything done that the task did not ask for.
Report findings ranked by severity with file:line. Do not fix yet.
```
