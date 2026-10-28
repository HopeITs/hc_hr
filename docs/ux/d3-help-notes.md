# D3 (part A) · Contextual help and tours

Task: D3 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-14), help/tour part only. The feedback channel (part B, AC-D3-03) is documented separately.
Date: 2026-10-27 · Branch `main` (working tree, HEAD `119a516f` at the time of writing) · Not committed.
Inputs: C1 menu/shell, B0/A5-M dialog contract ([interaction-contract.md](interaction-contract.md), [a5m-implementation.md](a5m-implementation.md)), A4 bilingual rules ([a4-implementation.md](a4-implementation.md)).
Status: **built and tested; help text PROPOSED, pending content review (§5); QA/tech-lead review pending.** No migration, no server endpoint, no data change.

## 1. What people see

- **Help button** in the header (sm and up), next to *Take a tour*. On phones it is in the account menu (*Help for this page* / *مساعدة لهذه الصفحة*): one more 44px button pushed the menu button 27px off the screen at 375px (M1), so it does not sit in the phone top bar.
- **"?" beside difficult fields**, opening the panel straight at the matching topic:

| Screen | Field | Topic |
|---|---|---|
| My leaves → new/edit request (dialog) | Leave type; Leave unit (annual only) | `leave-units` |
| All leaves → HR request for an employee (dialog) | Leave type; Leave unit | `leave-units` |
| Contract create / renew / edit (composer) | Position | `position-dependencies` |
| | Contract type | `contract-types` |
| | Auto renewal (create: under the dates; edit: termination section) | `auto-renew` |

- **The panel** is a drawer on the inline-end side (right in English, left in Arabic; full width on phones). It shows *On this page* first (from the route), then *Other topics*; a search box filters every topic in the page's language (Arabic folds hamza/alef, ya, ta marbuta and short vowels, so «اجازة» finds «إجازة»). A topic shows a title, one-line summary and 5–6 short points. *All help topics* goes back to the list. A footer line says the page's work stays as it is.
- Escape, the close button and a backdrop click close it (read-only dialog: ordinary dismissal per A5-M). Focus goes into the panel (topic heading, or the search box from the header) and back to the button that opened it.

## 2. How

| Part | Where | Rule |
|---|---|---|
| Registry | [`App\Support\HelpTopics`](../../app/Support/HelpTopics.php) | `TOPICS` (key → number of points, optional `permission`) and `ROUTES` (route name → topics). Resolved during the full page render, like `ProductTour`, never inside a Livewire update. `permission` (`contracts.view` via `hasActivePermission`) only decides relevance: an employee is not offered contract topics. It grants nothing; the text holds no figures or personal data. |
| Copy | `resources/lang/{en,ar}/help.php` | `help.panel.*` (UI), `help.trigger`, `help.topics.<key>.{title,summary,points.N,keywords}`. Result count uses `trans_choice` with all Arabic plural forms, precomputed for every possible count. |
| Panel | [`components/help/panel.blade.php`](../../resources/views/components/help/panel.blade.php), [`resources/js/help-panel.js`](../../resources/js/help-panel.js) | Mounted once in `layouts/app.blade.php` (signed-in only), **outside every Livewire component**, all topics embedded as data. No `wire:*`, no `$wire`, no fetch. `role="dialog" aria-modal="true"`, `x-trap.inert.noscroll` (stacks over a form dialog exactly like the confirm dialog: pauses its trap, restores it on close). |
| Triggers | [`components/help/trigger.blade.php`](../../resources/views/components/help/trigger.blade.php), `forms/radio-group` `help` prop | `type="button"`, `$dispatch('help-open', {topic})` only, `aria-haspopup="dialog"`, name "Help: <field>". Placed **beside** the label/legend, never inside (inside, its name would join the field's accessible name; the radio-group legend floats to allow that). 24px circle, 44px hit area via `::before`. |
| Header | [`components/help/button.blade.php`](../../resources/views/components/help/button.blade.php), `livewire/layout/header.blade.php` | Button (sm+) and phone-only account-menu item, both `$dispatch('help-open')`. |

**Server endpoints:** none. Nothing to classify in `config/access-control.php`.

### 2.1 Tours (step 1 of the task)

Checked `ProductTour`, `product-tour.js`, `<x-tour.overlay>`:

- **Optional:** *Skip tour*, the close button and Escape all end it; only the dashboard autostarts, once per user per browser.
- **Replayable:** the header button replays it; `start()` returns early when already open, so double clicks or repeated `tour-start` events never stack.
- **No duplicate overlays:** the overlay is in the page body (not `@persist`), so `wire:navigate` swaps it and Alpine destroys the old instance with its window listeners.

**No tour defect found; tour code unchanged.** Evidence in §3.

### 2.2 Defects found and fixed on the way (in the new code, by the browser check)

1. Opened from inside the leave dialog, focus stayed on the "?" behind the panel: the dialog's own trap was still active when the panel focused its heading and pulled focus back. The panel now re-checks focus at 60/200/500 ms, the same remedy the confirm dialog uses.
2. A click on plain text inside the panel dropped focus to `<body>` (the section was not focusable). It now has `tabindex="-1"`.
3. The live "N topics match" text showed the raw plural string. It is now `trans_choice` per count.
4. The header help button overflowed the phone top bar (above). Moved to the account menu below `sm`.

## 3. Acceptance evidence

### AC-D3-01 · Help answers the field's question in both languages without losing form state

Automated ([`tests/Feature/HelpPanelTest.php`](../../tests/Feature/HelpPanelTest.php), 13 tests):

| What | Test |
|---|---|
| Every mapped route exists and maps to a known topic; the four required topics exist | `test_every_mapped_route_exists…`, `test_the_required_topics_exist` |
| Every topic key in EN and AR, non-empty, same number of points, same topic set; panel UI keys in AR | `test_every_topic_has_the_same_copy_keys_in_english_and_arabic` |
| No pay amounts / currency / salary wording in the copy | `test_help_copy_holds_no_pay_amount` |
| Page defaults: composer → contract topics; leaves → leave topics; employee gets no contract topics | `test_a_page_defaults_to_its_own_topics…`, `test_topics_are_resolved_in_the_page_language` |
| Shell renders exactly one panel + header button for a signed-in user, topics embedded; none for guests | `test_the_shell_renders_the_panel…`, `test_guests_get_no_help_panel` |
| Panel has no Livewire ancestor and no `wire:*` / `$wire` (page does have components, so the check can fail) | `test_the_panel_sits_outside_every_livewire_component…` |
| Composer "?" are `type="button"`, no `wire:*`, not inside label/legend; entered values unchanged | `test_the_composer_help_triggers_cannot_submit_or_call_the_component` |
| Leave dialog: both "?" present inside the `wire:submit` form, plain buttons; values and help survive the form's own live round trips | `test_the_leave_form_keeps_its_values_and_its_help…` |
| Radio group without `help` is unchanged; with it, the legend name stays the field name | `test_a_radio_group_without_help_renders_no_trigger` |

Mutation check: making the trigger `wire:click` fails both form tests.

Browser (headless Chrome, synthetic SQLite copy `C:/laragon/tmp/hc-hr-d3help` copied from the G-C fixture, launch entry "hc-hr D3 help (synthetic SQLite copy)", port 8404; synthetic users `staff.alp` / `hr.alp`; scripts and raw results in [evidence/d3](evidence/d3/) `help-browser-check.mjs`, `help-phone-header-check.mjs`, `help-browser-results.json`):

| Scenario × EN/AR × 1440×900 / 375×812 | Result (all 8 runs of each) |
|---|---|
| Leave dialog half-filled (leave type picked, reason typed) → "?" beside Leave type | Panel opens at *Days or hours* / «أيام أم ساعات», focus on the topic heading; drawer at the end side (EN x 992–1440, AR x 0–448; phone full width); no sideways scroll. `help-01-*` |
| Back, search «weekend» / «عطلة» | 1 result (`leave-units`), live text "1 topic matches" / «موضوع واحد مطابق»; nonsense query → "No topics match" / «لا توجد مواضيع مطابقة». `help-02-*` |
| Escape (from search), then Enter on "?" and Escape straight from the topic | Panel closes, **leave dialog still open**, leave type and reason unchanged, focus back on the "?", in-page marker kept. `help-03-*` |
| Network while help was open | **0** Livewire requests, **0** document loads |
| Composer: contract type chosen → "?" beside Contract type → Escape | Topic shown, focus returns to the "?", chosen type and start date unchanged, 0 Livewire requests. `help-04-*` |
| Header button (desktop) / account menu (phone) | List with *On this page*: contract-types, position-dependencies, auto-renew; focus in search; backdrop click (desktop) or Escape (phone) closes, focus back on the opener / menu button. `help-05-*`, `help-06-*` |
| Phone header at 375 (EN/AR, employee and HR) | No header control off-screen, `scrollWidth` 375. `help-00-*` |

### AC-D3-02 · Tours can be skipped and replayed without duplicated overlays

Browser, EN 1440×900 (`hr.alp`, fresh profile): the dashboard tour autostarted (1 card); *Skip tour* closed it; five `wire:navigate` hops (leaves → contracts → home → leaves → home, same document kept) each left **exactly 1** tour overlay and **1** help panel; replay with a double click plus an extra `tour-start` event showed **1** card; Escape closed it and focus returned to the tour button. `tour-01-*`, `tour-02-*`. Server-side tour tests (`ProductTourTest`) unchanged and passing.

### Checks run

- `php artisan test` (PHP 8.5, SQLite `:memory:`): 1036 passed, 0 failed (§6).
- `./vendor/bin/pint --dirty`: passed. `npm run build`: passed.

## 4. Accessibility and RTL notes

- Dialog: labelled by its title; inert background; Escape/close/backdrop; focus returns (also from the closed phone menu, to its button). Topic list items are buttons with visible focus. Result count is announced (`aria-live="polite"`).
- Logical utilities only (`end-0`, `ps-9`, `start-3`, `ms-1`, `text-start`, `float-start`); slide-in direction and the back chevron flip with `rtl:`. Motion reduced with `motion-reduce:transition-none`.
- Product target 44px on all panel controls and on the "?" hit area (visible circle 24px).
- **Not done:** screen-reader pass, 200% zoom, 1280×720 and 812×375 (not in this assignment's viewport list).

## 5. Proposed help text for content review

Status of every string in `resources/lang/{en,ar}/help.php`: **proposed, pending content review** (HR product owner + Arabic reviewer). English was written here; there is no English guide. Each point was checked against the code named below. The Arabic guide [دليل-الاستخدام.md](../دليل-الاستخدام.md) was read and is **out of date on contract types** (§6 lists «محدد المدة / غير محدد / مؤقت», which no longer exist): the help follows the code.

| Topic | Points (EN summary) | Verified against |
|---|---|---|
| **leave-units** — Days or hours | Balances kept in hours; a day = weekly hours ÷ working days · Day/Hour only for annual leave · day requests skip the center weekend and holidays; maternity in calendar days · hour requests: one date, inside working hours, min 15 min · full time (≥40 h/week): over 4 h counts as a full day; part time exact · other types have a per-request maximum; December only as an exceptional leave | `LeaveRequestPricing` (`hoursPerDay`, `CALENDAR_DAY_TYPES`, `MINIMUM_MINUTES`, `HALF_DAY_HOURS`, `FULL_TIME_WEEKLY_HOURS`, `EXCEEDS_TYPE_MAX`, `DECEMBER_RESTRICTED`); leave form shows the unit only for `LeaveTypeCases::Annual` |
| **leave-balance** — Your balance | Annual released in three equal parts over the contract · other types in full · pending requests reserve at once · deducted on acceptance; rejected/cancelled returns it · service contracts and drafts have no balance | `LeaveBalance::eligibleAnnualLeaveHours`, `LeaveRequestPricing::availableHours/pendingHours/isExemptFromLeave`, `LeaveRequestForm` accept path |
| **contract-types** | Volunteer: from the 1st of next month to year end · Trial: 3 months, never auto-renews · Specific voluntary service: always freelance, no leave · max one year from start; full/part time and place belong to the contract · draft: reserves, no dates/pay, no seat | `ContractForm::updatedContractTypeId/validateTerms`, `config/contracts.php`, composer `chooseType()`, `ContractObserver` (draft) |
| **auto-renew** | Nightly check creates a successor with the same terms incl. pay schedule · starts the day after the end, runs to year end · on by default; trial and terminated never renew · turning it off starts the equipment return · only Governorate HR may change it | `ContractAutoRenewalService`, `routes/console.php` (daily 21:35), `ContractObserver::creating/updated`, `Contract::canAutoRenew`, existing `general.auto-renew-hint` |
| **position-dependencies** | City → program → position; center comes from the position · city directly or through the center, same rule for list and save · seats; "Full" cannot be chosen · draft takes no seat; renewal in place keeps the seat · moving frees the old seat; a seat frees when the contract ends | `Position::scopeInCity`, `Position::canAssign`, `ContractObserver`, `deactivate:contract` |

**Review questions:** (1) Is "Only Governorate HR can change automatic renewal" still exact (copied from the existing hint)? (2) Should the December rule be in leave help at all? (3) Contract type names are stored data (HR can rename them); the help uses today's names.

## 6. Limits and handoff

- **Final suite:** 1036 tests, 6127 assertions, **0 failures** (PHP 8.5, SQLite `:memory:`, working tree including the concurrent feedback work). Previous recorded baseline (C4): 1004 tests, 0 failures. One existing expectation changed on purpose: `PhoneShellTest` now expects **4** controls hidden on phones (the help button) and checks the phone menu help item.
- Help is static text in translation files; no per-role wording, no analytics of help use, no deep links by URL.
- Not every difficult field has a "?" yet: only the ones the task named. Adding one is `<x-help.trigger topic="…" :label="…"/>` beside the label plus a registry/lang entry.
- The header's old *User guide* link (Google Doc) is unchanged.
- Synthetic browser copy: `C:/laragon/tmp/hc-hr-d3help` (disposable). Its compiled-views folder had to be emptied after copying, because the copy gave stale compiled views newer timestamps than the edited Blade files.
- **Consumers:** X2-R, G-R (help/tour evidence); content reviewer (§5).
