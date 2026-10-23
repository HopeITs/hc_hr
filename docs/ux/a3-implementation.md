# A3: Accurate leave-impact summary and request status path

Task A3 (R-04) in [ux-prompts-v3.md](ux-prompts-v3.md). Built on branch `a3` (base `7fb086db`), 2026-10-21.
Status: **implemented, browser evidence recorded (§9, 2026-10-22), committed as `5ad4cb90` on branch `a3`, awaiting review** (QA engineer + technical lead).

Inputs: [leave-calculation-contract.md](leave-calculation-contract.md) (A3-D, approved 2026-10-20, decisions D-1 to D-9), [a3-r-implementation.md](a3-r-implementation.md) (A3-R selector), B0 feedback foundation (`WithFeedback`, toasts, confirm dialog).

## 1. What changed and why

A3-D found five places that each priced a leave request in their own way, and they disagreed (§1 of the contract). A3 puts one server-side owner, `LeaveRequestPricing`, behind all of them. The consumers were switched in the contract's order (§11): preview, then validation, then deduction.

| Area | Before | After |
|---|---|---|
| **Pricing service** | — | `app/Services/LeaveRequestPricing.php`: `quote()`, `quoteLegacy()`, plus `pendingHoursFor()` and `hoursPerDayFor()` for the balance card and the saved-request display. Value objects are `app/Support/Leave/LeaveWindow.php` and `LeaveQuote.php`. Rule set `leave-calc/1`. |
| **Live preview** (A3 step 2) | None. The dialog showed a day count from its own arithmetic (#3). | `MyLeaveRequestModule::leaveImpact()` (a `#[Computed]` property) prices the typed fields on every render. The summary sits under the dates in `index.blade.php` (partial `partials/impact-summary.blade.php`). It shows available, reserved by other pending requests, this request (days + hours, and in hours), and projected. It also shows counted working or calendar days, excluded dates with their reason, warnings, blocking errors, and a "How this is calculated" breakdown. The heading says "Estimate: these figures are proposed. They become final once the request is decided." Dates and times are `wire:model.live`, and the time pickers have a new `live` option (debounced 400 ms). |
| **Confirmation dialog** | #3 arithmetic | It shows the quote's duration, the projected balance (requester only) and the warnings, plus the estimate note. |
| **Validation** | `calculateRequestedHours` (#4, global Carbon weekend, +1 day), `canRequestAnnualLeave`, `validateMaxDuration` (#5) and the half-day `$maxHours` rule | All removed. `quote()->errors` become field-linked errors with their `leave_quote.codes.*` message (`LeaveRequestForm::assertQuoteAllows()`, mapping below). The rules keep only the shape of the input: required fields, `date`, `H:i`, end ≥ start, end time after start time, and a new maximum span of 366 days. |
| **Write-time recheck** | Annual cap outside any lock | `storeOnce`/`updateOnce` call `quote()` again inside the existing transaction, after the employee and balance `lockForUpdate`. A balance that changed in another session gives the same field error on the leave type. |
| **Snapshot** (D-5) | — | On store, and on update while pending, `computed_hours` and `rule_version` come from the server-side quote. They are never form properties, so the browser cannot set them. |
| **December** (D-7) | — | An "exceptional request" checkbox (EN and AR label and hint) appears only when the typed period touches December. It feeds `LeaveWindow::$exceptional` and is stored in `is_exceptional`. A stale tick on a period that no longer touches December is ignored and stored as `false`. |
| **Approval deduction** | `used += duration_in_hours` (#2, legacy accessor) | `used += computed_hours`, or `quoteLegacy()` for rows without a snapshot. This happens inside the existing request lock, with a balance lock added. The annual balance is rechecked at decision time: if it no longer covers the request, the decision is refused on `form.status` (`leave_quote.decision.exceeds_balance`), nothing is written and the request stays pending. |
| **Edit of an accepted request** | Read-modify-write of `used`, outside a lock, no recheck | One transaction. It locks the request, then the employee, then the balance(s), and rechecks `Gate::authorize('update')` on the locked row. Then `used += new − old`, where old = stored `computed_hours` ?? `quoteLegacy(original)` and new = the fresh quote. The new total is re-validated against the committed balance, and the snapshot is updated. A content-only edit (reason, alternative) prices nothing and leaves `used` and the snapshot alone. If the balance changes, `old` goes back to the old balance and `new` is charged to the new one. |
| **`AllLeaveRequestModule`** | Its own `resolveDuration` (calendar days, #3-like) | It has no deduction path of its own. Decisions and edits go through the same `LeaveRequestForm` methods. Its confirmation now uses the quote's duration and `checkedQuote()`. It shows **no** balance figures, because managers use this page too (§9 of the contract). |
| **Saved request display** | `LeaveRequest::getDurationAttribute` (#1) | When `computed_hours` is set, it is worded as days + hours + minutes with the contract's own day length. Otherwise the legacy wording stays (§4.2). This covers both tables, both detail dialogs, the decision dialog and the dashboard "upcoming leaves" card. |
| **Balance card pending** | `Employee::availableLeaves` summed pending **days** and **hours** separately (`diffInWeekDays + 1`, global `Carbon::setWeekendDays`) | `pendingHoursFor()` from the service (snapshot or legacy per row, one unit), split into days + hours with `hoursPerDayFor()`. The dashboard `MyLeavesCard` reads the same method, so it changed too. The change was contained, so it is done here rather than left as a follow-up. |
| **HR employee scope** | `LeaveRequestForm::targetEmployee()` took any `form.employee_id` for an HR role | It is limited to `activeCityIds()`, like the employee dropdown. `employee_id` is client-writable (security note, §6). |

Error code → field (`LeaveRequestForm::fieldForCode()`):

| Code | Field |
|---|---|
| `exceeds_balance` (annual only; other types warn, D-6), `schedule_unknown`, `no_leave_entitlement` | `form.leave_balance_id` |
| `no_countable_days`, `december_restricted`, `exceeds_type_max` | `form.start_at` (hourly: `form.leave_date`) |
| `below_minimum`, `outside_working_hours` | `form.end_hour` |
| `invalid_window` | `form.end_at` (hourly: `form.end_hour`) |

Warnings never block. Input is kept on every error (the existing `keepingInputOnFailure` wrapper, which now also covers `is_exceptional`).

### Files

| File | Change |
|---|---|
| `app/Livewire/Forms/LeaveRequestForm.php` | `window()`, `periodTouchesDecember()`, `quoteFor()`, `previewQuote()`, `checkedQuote()`; private `assertQuoteAllows`, `fieldForCode`, `snapshot`, `deductionHours`, `assertDecisionFitsBalance`, `adjustUsed`, `windowDiffersFrom`, `spanTooLong`; new `is_exceptional`; rules trimmed; decision, store and update rewritten as above; `lockRequesterRows(employee, ...balances)`; `targetEmployee()` city-scoped. Removed `calculateRequestedHours`, `validateMaxDuration`. |
| `app/Livewire/Employee/Leave/MyLeaveRequestModule.php` | `leaveImpact()` (computed), `describeQuote()`, quote-based `confirmSave`/`confirmationMessage`; `resolveDuration` removed. |
| `app/Livewire/Employee/Leave/AllLeaveRequestModule.php` | Quote-based `confirmSave`/`confirmationMessage`; `resolveDuration` removed. |
| `app/Livewire/Employee/Leave/Concerns/FormatsLeaveDuration.php` | `formatLeaveSplit($hours, $hoursPerDay)`. |
| `app/Services/LeaveRequestPricing.php` | `pendingHoursFor()`, `hoursPerDayFor()`, `splitDays()` (the shared days + hours split for display, §9). |
| `resources/views/components/leave-card.blade.php` | Fits 375 px, RTL-safe gap and divider (§9). |
| `app/Models/LeaveBalance.php` | Day length from `LeaveRequestPricing::hoursPerDayFor()` instead of `weekly_hours ÷ 5` (§9). |
| `app/Livewire/Employee/Leave/EmpLeaveBalanceTable.php`, `app/Livewire/Contract/ContractsTable.php`, `app/Exports/EmployeeLeaveBalanceExport.php`, `app/Exports/ContractExport.php` | Give each balance its loaded contract, so the day length costs no query per row. |
| `app/Models/LeaveRequest.php` | Snapshot-aware `duration`. |
| `app/Models/Employee.php` | `availableLeaves()` pending from the service. |
| `resources/views/livewire/employee/leave/index.blade.php` | Live inputs, December checkbox, summary. |
| `resources/views/livewire/employee/leave/partials/impact-summary.blade.php` (new) | The summary. |
| `resources/views/components/leave/request-confirmation.blade.php` | Optional `projected` and `warnings` props, isolated dates. |
| `resources/views/components/forms/time-picker.blade.php` | Optional `live` prop (default off, so other forms are unchanged). |
| `resources/lang/{en,ar}/leave_quote.php` | `preview.*`, `exceptional.*`, `decision.exceeds_balance`. |
| `resources/lang/{en,ar}/leaves.php` | `validation.range-too-long`. |
| `config/access-control.php` | `leaveImpact` classified as exempt (read-only, self-scoped). |
| `tests/Feature/LeaveRequestAccountingTest.php` (new) | §2. |
| `tests/Feature/LeaveRequestSubmitFlowTest.php`, `LeaveRequestWorkflowTest.php`, `LeaveSelectorStateTest.php` | Dates made deterministic (see §2, "Existing tests"). |

## 2. Acceptance evidence (automated)

| AC | Test (file :: name) | Result |
|---|---|---|
| **AC-A3-01** Preview == stored == deducted, every A3-D fixture incl. hourly and calendar-day exceptions | `LeaveRequestAccountingTest::test_preview_snapshot_and_deduction_are_the_same_figure`, 8 data sets: **F1** 40 h, **F2** 48 h, **F4** national holiday 32 h (holiday listed and warned), **F6** hourly exact 3.33 h, **F8** hourly over 4 h = 8 h, **F10** six-day week 24 h, **F11** maternity 30 calendar days 240 h (with the December flag, see §5), **F12** pending reserves (preview 32 h, pending 24 h shown and warned). Each run checks the preview value and the rendered summary, then `computed_hours` + `rule_version` after send, then the `used` delta after HR accepts. | 8/8 pass |
| | `test_the_saved_request_is_listed_with_its_stored_amount`, `test_the_balance_card_pending_figure_comes_from_the_pricing_service`, `test_a_legacy_pending_request_is_deducted_with_the_legacy_rule` | pass |
| | `LeaveRequestPricingTest` (service, F1–F20 and more) | 35/35 pass |
| **AC-A3-02** Reversed dates or insufficient balance explain the problem without losing input | `test_an_insufficient_annual_balance_is_a_field_error_that_keeps_the_input` (dialog and write path; `form.leave_balance_id` = `leave_quote.codes.exceeds_balance`; all inputs kept; no row) | pass |
| | `test_a_non_annual_request_over_its_balance_only_warns` (D-6) | pass |
| | `test_hourly_errors_are_linked_to_the_end_time_and_the_half_day_cap_is_gone`, `test_a_range_of_weekend_days_only_is_reported_on_the_start_date` | pass |
| | `LeaveRequestSubmitFlowTest`: reversed dates on `end_at`, own overlap on `start_at`/`leave_date`, partial form | pass |
| **AC-A3-03** Status and next actor; duplicate submit = one request, one set of notifications | `LeaveRequestSubmitFlowTest` (13 tests, unchanged in substance) | 13/13 pass |
| Stale balance (accepted elsewhere between preview and send) | `test_a_request_accepted_in_another_session_between_preview_and_send_is_refused` | pass |
| Decision-time recheck | `test_accepting_is_refused_when_the_annual_balance_no_longer_covers_the_request` | pass |
| Edit of an accepted request | `test_editing_an_accepted_request_moves_used_by_the_difference` (+8 h, then −16 h), `test_editing_a_legacy_accepted_request_uses_its_legacy_amount_as_the_old_figure` (content-only edit leaves `used` alone), `test_editing_an_accepted_request_beyond_the_annual_balance_is_refused` | pass |
| December (D-7) | `test_december_is_blocked_without_the_exceptional_flag_and_allowed_with_it` (checkbox only in December, error on `start_at`, `is_exceptional` stored), `test_a_stale_exceptional_tick_on_a_november_period_is_not_stored`, `test_maternity_across_december_without_the_flag_is_blocked` | pass |
| Partial or invalid form, owner-only preview | `test_a_partial_or_reversed_form_shows_a_hint_or_an_error_and_never_throws`, `test_the_preview_never_prices_another_employees_balance` | pass |

`LeaveRequestAccountingTest`: 18 test methods, **25 runs**, all pass.

**Existing tests.** Seven existing tests failed only because of the calendar. They picked dates as `now()->addMonth()`, and on 2026-10-21 that is Saturday 21 November. A weekend-only range is now `no_countable_days` instead of "1 day", and `addMonths(2)` lands in December (D-7). The submit-flow class now freezes the clock at 2026-10-21 and uses fixed working days. `LeaveRequestWorkflowTest` and `LeaveSelectorStateTest` use a `workingMonday()` helper: a Monday a month or more ahead whose Monday–Tuesday is outside December. No assertion was weakened.

### Checks run

- `php artisan test --filter="Leave|LivewireMutationCoverage|WithFeedback|Access"`: **271 passed**, 0 failed.
- Full suite: see §8.
- `vendor/bin/pint` on every changed PHP file: clean.
- No JS change. The time picker's `live` option is Blade-only, so `npm run build` is not needed.

## 3. Decisions and implementation choices

| Choice | Detail |
|---|---|
| Holiday "national" (D-4) | The `holidays` morph has no national flag. A holiday attached to the City whose slug is `City::NATIONAL_SLUG` (`SYR`, the nationwide scope that HR-SYR owns) counts as national. City = the center's city (else the position's). Program = the position's program in every governorate. |
| D-1 fallback order | Center weekend first. Then a center of the same program in the governorate, tried in this order: the center's city, the position's city, the employee's city (lowest center id, for stability). Then the code default `0,6` with the `balance_unknown_weekend` warning. |
| Irregular schedule (D-8) | Weekly hours below 40 **and** `schedule_mode = Flexible`. Hours only; a day request is `schedule_unknown`. A part-time fixed schedule keeps a day length (F9). |
| Probation | A trial contract (`config('contracts.map_by_id')` category `trial`). Beyond 3 days is the `probation_excess` warning, never an amount. |
| `invalid_window` | Not in contract §5. It is an input-shape guard so the live preview reports a reversed window instead of throwing. In the form, the rules catch reversed input first. |
| Decision-time and accepted-edit checks ignore other pending requests | At submit, pending requests reserve balance (§3.4). Accepting one request does not commit the others, so the decision and the edit of an accepted request are checked against the **committed** balance (eligible to date − used, plus the request's own previous deduction for an edit). The others are rechecked when they are decided. If two legacy pending requests (sent before reservations existed) each fit alone, the first can be accepted and the second is refused. Neither is blocked by the other. |
| Content-only edit of an accepted request | Not re-priced, so a typo fix never rewrites a historical deduction. |
| Pending edit of a legacy request | Re-priced under `leave-calc/1` and given a snapshot. It is pending, so no `used` is involved. A legacy pending request inside December needs the flag when edited. |
| Maximum span 366 days | Pricing walks the range day by day. A longer range is refused by the `end_at` rule, and the preview shows the neutral hint for it. |
| Lock order | Update: request, then employee, then balance(s) in id order. Decision: request, then balance. Store: employee, then balance. No path takes them in reverse. |

## 4. Next actor (for C3)

The next-step text (`app/Services/LeaveRequestNextStep.php`) comes from the code that runs. It does not come from DEC-05:

- Pending: the deciding audience is the requester's managers who hold `manager`/`general-manager` (`LeaveRequestPolicy::decide` via `scopeManageableBy`), **or** city HR. Any one of them decides, and there is no second approval.
- **Divergence from the DEC-05 revision (2026-10-20):** that revision says only the **direct manager** decides, and HR is notified but does not approve. Today HR can also decide, and the text says "your manager or the HR team in :city". The technical manager is still read in `scopeManageableBy` (`tech_manager_id`). The revision says that role no longer exists.
- **December exceptional (D-7)** needs manager **and** HR approval. That is not built (C3). Until C3, an exceptional request is accepted by a single decision like any other. The requester's hint and the `december_exceptional` explanation already say both approvals are needed.

## 5. Open items

| Item | Owner |
|---|---|
| **F11 maternity across December** is blocked by D-7 unless marked exceptional. The contract's F11 range (2 Nov – 1 Dec) ends in December. Whether maternity (or any statutory leave) is exempt from D-7 needs an HR answer. Until then, the AC-A3-01 run for F11 uses the flag. | HR product owner |
| **Per-project holidays** (program × governorate) cannot be expressed by the `holidays` morph. A program holiday applies in every governorate. A schema change is needed. | Tech lead |
| **Service-agreement absence flow**: `no_leave_entitlement` blocks the request, but the manager-approved, no-deduction absence (A3-D §8) has no home. `leave_balance_id` is required. | Product owner + tech lead |
| **A5-M dirty-discard prompt** on the leave dialog. Cancel closes locally, but there is no "Keep editing / Discard" for a dirty form yet. | A5-M |
| **Forged `type=hour` on a non-annual balance**: the UI offers hours for annual only, and `updatedFormLeaveBalanceId` resets the unit. The server still accepts `type=hour` for any balance. Not changed (policy). | A3 follow-up / tech lead |
| **Double approval for December exceptional** (§4). | C3 |
| HR live preview on *All leaves*: not added, because that page is shared with managers who must not see balances (§9). If wanted, it should check the active role (HR, city-scoped), not the page. | C3 / A3 follow-up |
| **Entitlements are still generated in 8 h days.** On a six-day week the cards now show, for example, Death Degree 2 as "1 day and 1 h 20 min" (8 h) and Death Degree 1 as "8 days and 2 h 40 min" (56 h). The hours were always there, and pricing already charges a day at 6.67 h. Only the wording now shows the leftover. Should generation use the contract's day length? That is HR's decision; generation was kept separate on purpose (A3 step 1). | HR product owner + tech lead |
| The balance card's `remaining` is `deserved − used`, not eligible-to-date. Its day length now comes from the service (§9). | A3 follow-up (product decision: should the card show "available now"?) |
| HR editing an accepted leave whose start date is in the past fails the existing `start_at ≥ today` rule (pre-existing). | A3 follow-up / C3 |
| The time picker's `min="09:00" max="21:00"` is hard-coded, not the center's hours (pre-existing). The server checks the real hours. | B2 |

## 6. Security notes

- The quote carries hours and dates only. No salary, pay tier or pay per day appears in the quote, the summary, the confirmation or the Livewire payload. `User::canViewSalary()` is not involved.
- The live summary is owner-only. `previewQuote()` prices only a balance of the **signed-in** employee's own active contract and ignores `form.employee_id` (test `…never_prices_another_employees_balance`). It is rendered only in *My leaves*. *All leaves* (managers + HR) shows the requested duration, never balances.
- `computed_hours`, `rule_version` and `is_exceptional` are written only from the server's quote and window. None of them is a client-writable property (the flag is, but it is re-derived: stored only when the period touches December).
- Every authoritative write rechecks inside a transaction with row locks: store (employee + balance), update (request + employee + balances, plus `Gate::authorize('update')` on the locked row), decision (request + balance, plus the existing `Gate::authorize('decide')`).
- **Pre-existing hole closed:** `targetEmployee()` accepted any `form.employee_id` for an HR role, so an HR-ALP user could validate and create a request against an employee in another city by forging the property. It is now limited to `activeCityIds()`.
- `leaveImpact` is a public computed method. It is classified as exempt in `config/access-control.php` (read-only, self-scoped), and `LivewireMutationCoverageTest` passes.

## 7. Rollout and recovery

- **Migration** `2026_10_21_000001_add_pricing_snapshot_to_leave_requests_table` is additive: `computed_hours` (nullable decimal), `rule_version` (nullable), `is_exceptional` (default false). There is **no backfill**: a null snapshot means "priced before leave-calc/1", and those rows keep the legacy rule for display and acceptance. It was run only on the test database (sqlite `:memory:`), never on the operational MySQL database.
- **Deploy:** `php artisan migrate`, then the code. No configuration change.
- **Rollback:** revert the consumers (form, modules, views, `LeaveRequest::duration`, `Employee::availableLeaves`). The columns can stay; old code ignores them. Do **not** run the migration's `down()` once snapshots exist, because it drops them. After a rollback, pending requests sent under `leave-calc/1` would be deducted on acceptance with the legacy accessor, which can differ (for example, an hourly request over 4 h, a weekend-only range, or a holiday). Reconciliation would be a dry-run report of `computed_hours` against the legacy amount for requests decided after the rollback (contract §4.4). Nothing here runs it.

## 8. Full suite

Baseline before this step (A3 working tree, already-uncommitted service + submit flow): 670 tests, 4 known unrelated failures (ContractAutoRenewalWorkflow, ContractCreatePerformance, ContractPayClause, ContractRenewal).

After (PHP 8.5, sqlite `:memory:`): **695 tests, 691 passed, 4 failed** (4003 assertions). The 25 new tests are `LeaveRequestAccountingTest`. The 4 failures are the same known, unrelated ones: ContractAutoRenewalWorkflow (warning command same day), ContractCreatePerformance (composer sections), ContractPayClause (PDF `ViewException`) and ContractRenewal (malformed query string). No new failure.

After the §9 fixes (2026-10-22): **696 tests, 692 passed, 4 failed** (4010 assertions). The new test is `test_whole_days_survive_a_repeating_day_length`, and the same four unrelated failures remain.

After the card day-length fix: **697 tests, 693 passed, 4 failed** (4015 assertions). The same four unrelated failures remain.

## 9. Browser evidence

Run 2026-10-22 on a disposable copy of the X2 synthetic SQLite fixture (`C:/laragon/tmp/hc-hr-a3`, `127.0.0.1:8394`, launch entry "hc-hr A3 (synthetic SQLite copy)"), assets built from this working tree. Personas `staff.alp@example.test` (requester, annual balance 13, six-day week at 40 h, so 6.67 h a day) and `hr.alp@example.test`. No real data.

| Check | Locale / width | Result |
|---|---|---|
| Hint before the form is complete | EN, desktop | "Choose a leave type and complete the dates…" under the dates. |
| **Preview vs persisted vs deducted** (AC-A3-01), annual 16–20 Nov 2026 | EN, desktop | Summary: available 12 days, reserved 2 days 2 h 40 min, this request **5 days** (5 working days, 33 h 20 min), left 4 days 4 h, warning about pending requests. Confirmation: duration 5 days, same projection, estimate note. Saved: `computed_hours` **33.33**, `rule_version` `leave-calc/1`, `is_exceptional` 0. Table: "5 Days". Card pending went from 2 d 2 h 40 min to 7 d 2 h 40 min. HR (AR) list and decision dialog: "5 أيام". After HR accepted: `leave_balances.used` 0 → **33.33**. |
| Status and next actor (AC-A3-03) | EN | Toast "Leave request sent. Status: Pending." and the panel "Next step: Waiting for a decision from the HR team in Aleppo, which has been sent a notification." One request row and one notification were written. |
| Reversed dates (AC-A3-02) | AR, 375 | Summary: «لا يمكن إرسال هذا الطلب بعد — يجب أن تكون النهاية بعد البداية». Save: the error appears under «تاريخ النهاية», and the dates, type and reason are kept. |
| Balance shortfall (AC-A3-02) | AR, 375 | 23–28 Nov (6 days, 40 h): projected «ينقص يوم واحد وساعتان و40 دقيقة». Save: «هذا الطلب يتجاوز رصيدك المتبقي.» under «نوع الإجازة», all input kept, no row. |
| December (D-7) | AR, 375 | 30 Nov – 1 Dec: the checkbox «هذه إجازة استثنائية في كانون الأول» appears with its hint, and the summary blocks with the December reason. After ticking it, the block goes away and the summary shows 2 days, left 2 days 4 h. |
| Cancel | AR, 375 | Closes the dialog locally. Nothing is written. |
| Width | AR, 375 | `scrollWidth` = 375 on *My leaves* and *All leaves* after the card fix below. |

**Found and fixed during the browser pass:**

- **Whole days shown as days + hours.** With a day length that repeats (40 ÷ 6 = 6.6667 h), five days are stored as 33.33 h. The `floor((h + 0.001) / perDay)` split then gave "4 days and 6 hours and 40 minutes" in the summary, the confirmation, the saved-request table and the card's pending figure. The same leftover 0.01 h also showed up as a stray "and 1 minute" in the projection. The three copies of that split (`FormatsLeaveDuration::formatLeaveSplit`, `LeaveRequest::snapshotDuration`, `Employee::availableLeaves`) now share `LeaveRequestPricing::splitDays()`. Anything within 0.01 h of a whole day counts as that day, and the remainder is rounded to the minute. This is display only: stored and deducted hours are unchanged. Test: `LeaveRequestPricingTest::test_whole_days_survive_a_repeating_day_length`.
- **The *My leaves* page was 634 px wide at 375 px** (it existed before A3, in `components/leave-card.blade.php`). The annual card's text column had `min-w-full` next to the 125 px number. It is now `flex-1 min-w-0`, the number is 88 px below `sm`, and the annual card spans two columns from `sm` up (it is chosen by `isAnnual()`, not by `$loop->first`). `space-x-8` / `border-l` became `gap-*` / `border-s`, so in RTL the gap and the divider are on the correct side.

- **The annual card said 10 days, the summary 12, for the same 80 h.** `LeaveBalance` split hours into days with `weekly_hours ÷ 5` (8 h), and the summary used the contract's own day length (6.67 h on a six-day week). `LeaveBalance::workHoursPerDay()` now reads `LeaveRequestPricing::hoursPerDayFor()` (memoized per instance) and splits with `splitDays()`. Every reader of `deserved_formatted` / `used_formatted` / `remaining` / `int_total` / `int_remaining` follows it: the *My leaves* cards, the dashboard card, the HR leave-balance table and edit dialog, the contracts table, and both Excel exports. For a five-day week nothing changes (40 ÷ 5 is still 8). Six-day weeks now show more, shorter days. An irregular schedule (flexible, under 40 h) shows hours only, as D-8 requires. This is display only: stored hours do not change. To avoid a query per row, the tables and exports give each balance its already-loaded contract (`setRelation('contract', …)`, with `contracts.position.center` eager-loaded in the balance table). Test: `LeaveRequestAccountingTest::test_the_balance_card_and_the_preview_count_the_same_days`.

**Still different on purpose:** the card's *remaining* is `deserved − used` for the year. The summary's *available now* is the eligible-to-date amount (section rule) − used. When the year's sections have not all opened, the two can differ in hours. They carry different labels.

Not checked in the browser: hourly requests (F6/F8), maternity and other calendar-day types, a locale switch with the dialog open, and a real screen reader.

## 10. Handoff

- **G-A:** AC evidence in §2 (automated) and §9 (browser), open items in §5, security in §6, rollout in §7. "Done" still needs the QA + tech lead review.
- **C3:** The next-actor source and its DEC-05-revision divergence are in §4. Build the December exceptional double approval on `leave_requests.is_exceptional`. Approval must deduct `computed_hours` ?? `quoteLegacy()` inside the lock, as `LeaveRequestForm::submitDecision()` does now. Do not reimplement date arithmetic. Use `LeaveRequestPricing` (`quote()`, `excluded_dates`, `pendingHoursFor()`).
- **C5:** Calendar and coverage read `excluded_dates` and `pending_hours` from the service (contract §11). A saved request's amount is `computed_hours` when set.
