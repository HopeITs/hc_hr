# A3-D · Leave calculation and preview contract (proposed)

Task: A3-D from [ux-prompts-v3.md](ux-prompts-v3.md) (requirements R-04, R-12).
Status: **approved 2026-10-20** by the HR product owner and the technical lead.
Recorded: 2026-10-20. Design and discovery only: no product code, schema or data was changed. Every example below is synthetic.

Consumers: A3-R, C3-D, C5-D (and the future `LeaveRequestPricing` implementation).
Predecessors consulted: [start-state.md](start-state.md) (P0; header and baseline only), [test-baseline.md](test-baseline.md) (A1; searched for leave tests only), [decisions.md](decisions.md) (DEC-03, settled 2026-10-16).

---

## 1. What the code does today (trace)

Five places compute a request's size. They do not agree.

| # | Where | Used for | Day count | Hours | Weekend source |
|---|---|---|---|---|---|
| 1 | `LeaveRequest::getDurationAttribute` | Display of a saved request | `diffInWeekDays + 1` | `floatDiffInRealHours`, shown as h + min | `center.weekends`, else `0,6` |
| 2 | `LeaveRequest::getDurationInHoursAttribute` | **Final deduction** (`used += …` on accept, and on edit of an accepted request) | same as #1 | `days × weekly_hours / 5` | same as #1 |
| 3 | `MyLeaveRequestModule::resolveDuration` | **Preview** in the confirmation dialog | `diffInDaysFiltered(…) + 1` | raw `floatDiffInHours`, no rounding | same list, passed in |
| 4 | `LeaveRequestForm::calculateRequestedHours` | **Annual cap check** (`canRequestAnnualLeave`) | `diffInDaysFiltered(!isWeekend, endOfDay) + 1` | `floatDiffInHours` | **global** `Carbon::isWeekend()`, not the center |
| 5 | `LeaveRequestForm::validateMaxDuration` | Non-annual cap vs `leave_types.base_days` | `diffInDaysFiltered(…) + 1`; calendar days for maternity types 4 and 5 | none | `center.weekends`, else `0,6` |

A sixth, `Employee::availableLeaves`, sums pending requests as **days** (`diffInWeekDays + 1`) and **hours** separately, so the "pending" figure on the balance card is in different units from `used`/`deserved`, which are hours.

### Observed differences (probe run, synthetic dates, PHP 8.5 + installed Carbon)

| Case | #1/#2 accessor | #3 preview | #4 annual check | True working days |
|---|---|---|---|---|
| Mon–Fri, weekends `0,6` | 5 | 5 | **6** | 5 |
| Sun–Thu, weekends `5,6` | 5 | 5 | **6** | 5 |
| Sun–next Thu, weekends `5,6` | 10 | 10 | **11** | 10 |
| Sat–Sun only, weekends `0,6` | **1** | **1** | 1 | **0** |
| One weekday | 1 | 1 | **2** | 1 |

Findings, each backed by the table or the source:

1. **Preview and annual check disagree.** #4 over-counts by one day because it extends the end to `endOfDay()` and then adds 1 again.
2. **#4 ignores the center's weekend.** It calls `isWeekend()`, which reads Carbon's process-wide static weekend list. #1 and #2 call `Carbon::setWeekendDays()` and never restore it, so the answer depends on which request ran first in the same worker. This is a correctness risk, not only a style issue.
3. **A range made only of weekend days counts as 1 day**, never 0, because of the unconditional `+ 1` after a filtered diff.
4. **Holidays are not considered anywhere.** A `holidays` table exists (polymorphic to City and Program, `is_recurring`), but no leave code reads it. `CalendarCard` uses sample data from `config('samples.calendar.holidays')`.
5. **The day→hour divisor is hard-coded as five days** (`Contract::getWorkHourPerDayAttribute` = `weekly_hours / 5`, typed `int`; the same `/5` is repeated in `LeaveBalance::workHoursPerDay`, `LeaveRequestForm::rules` and `getDurationInHoursAttribute`). A six-day schedule, or 37.5 h/week, gets a wrong or truncated day length. `weekly_hours = 0` silently prices a day at 0 hours.
6. **Hourly rules do not match DEC-03.** There is no quarter-hour minimum or rounding. The form caps an hourly request at half a working day (`weekly_hours / 5 / 2`), so "over 4 hours counts as a full day" can never trigger. Part-time vs full-time is not distinguished.
7. **Pending requests do not reserve balance.** `canRequestAnnualLeave` compares against `eligible − used` only. Two pending requests can each pass while together exceeding the balance. DEC-03 says pending requests reserve immediately.
8. **Non-annual types are checked per request against `base_days`**, not against the remaining balance (`deserved − used`).
9. **Weekend fallback.** `0,6` (Sunday + Saturday) is a code default when `centers.weekends` is empty. DEC-03 says the governorate's weekend applies to volunteers with no center, but `cities` has no such column (already noted in `decisions.md`).
10. **Edit of an accepted request** updates `used` with a read-then-write (`used - old + new`) outside a lock and without checking the new total against the balance.
11. The accessor's calendar-day exception covers only maternity types (ids 4 and 5). Whether sick, marriage, paternity and bereavement are calendar days is not stated in the code or in `decisions.md`.

---

## 2. Proposed contract

One server-side service computes everything. Preview, validation, the pending figure on the balance card, the approval deduction and the C3-D/C5-D calendar all call it; none of them keeps its own date arithmetic.

### 2.1 Service (proposed name `LeaveRequestPricing`, in `app/Services/`)

```
quote(Contract $contract, LeaveBalance $balance, LeaveWindow $window, ?LeaveRequest $excluding = null): LeaveQuote
```

`LeaveWindow` is either a date range (`type = day`) or one date plus start/end time (`type = hour`). `$excluding` removes the request being edited from the pending total. The function is pure: it reads, it never writes, and it never mutates global Carbon state (it passes the weekend list explicitly).

### 2.2 Result (`LeaveQuote`)

| Field | Meaning |
|---|---|
| `unit` | `hours` is the storage and arithmetic unit (matches `leave_balances.deserved/used`). Days are a display conversion only. |
| `requested_hours` | Rounded per §3. |
| `requested_days_display` | `requested_hours ÷ hours_per_day`, for the UI sentence only. |
| `counted_days` | Dates that count. |
| `excluded_dates` | `[{date, reason: weekend\|holiday}]`, so the preview can say why a date is skipped. |
| `available_hours` | For annual: eligible-to-date − used (existing section rule in `eligibleAnnualLeaveHours`). For others: `deserved − used`. |
| `pending_hours` | Sum of `quote()` for this balance's pending requests, excluding `$excluding`. |
| `projected_hours` | `available − pending − requested`. |
| `explanation[]` | Translation keys plus parameters, never pre-rendered English (A4 needs EN/AR). |
| `warnings[]` / `errors[]` | Stable codes (§5). Errors block, warnings advise. |
| `rule_version` | Constant string, e.g. `leave-calc/1`, so a stored or logged result can be traced to a rule set. |

**Excluded on purpose:** salary, pay tiers, pay per day and anything derived from `contracts.salary`. The quote contains hours and dates only. A deduction that "costs money" (probation excess, half-pay sick) is expressed as a warning code, never an amount.

### 2.3 Who consumes what

| Consumer | Today | After |
|---|---|---|
| Confirmation dialog (`confirmationMessage`) | #3 | `quote()` → `requested_*`, `excluded_dates`, `projected_hours`, `warnings` |
| Form validation (`validateMaxDuration`, annual cap) | #4, #5 | `quote()->errors` |
| Approval (`submitDecision`) | #2 | `quote()` recomputed inside the lock, or the stored value if §4.3 is approved |
| Edit of accepted request | #2 twice | one transaction: `used` adjusted by `new − old`, new total re-validated |
| Balance card pending figure | `Employee::availableLeaves` | `pending_hours` from the service (single unit) |
| Saved request display | #1 | `requested_*` of the same service, with the historical rule for old rows (§4.2) |

---

## 3. Rules

### 3.1 Already settled (DEC-03, preserve)

- Weekend days come from the **center** (`centers.weekends`); no center → governorate weekend.
- Official holidays inside a leave are not counted.
- Full-time hourly: `hours ÷ 8`; a request over 4 hours is one full day. Part-time: hours only.
- Hourly leave: minimum a quarter hour; half-day allowed; accumulates and is deducted from the annual balance.
- Pending requests reserve balance immediately; released on rejection or cancellation.
- Historical requests stay as they are; the unified rule applies to new requests only.

### 3.2 Proposed to implement them (technical choices)

- **Schedule.** Day length = `weekly_hours ÷ working_days_per_week`, where `working_days_per_week = 7 − number of weekend days of the applicable weekend`. This replaces the hard-coded `/5`. It gives 5 for a two-day weekend, 6 for a Friday-only weekend. Stored as a float, never cast to int.
- **End date is inclusive.** One date in, one date out.
- **Counting.** Iterate dates from start to end; skip weekend days and holidays. Zero countable days is an error, not 1 day.
- **Hourly conversion.** Request within one day, bounded by the center's open/close times (existing rule). Rounded per §3.3.
- **Weekend list is passed explicitly.** No `Carbon::setWeekendDays`.
- **Unit.** Everything is hours until the display layer.

### 3.3 Rounding (settled: D-3a, D-3b)

| Step | Proposed |
|---|---|
| Hourly request | **Exact** (no rounding), minimum 15 minutes; shorter is an error. |
| Full-time, hourly request over 4 h | Treated as one full day (`hours_per_day`). Exactly 4 h stays 4 h. The old half-day cap is removed (D-3b). |
| Part-time (weekly hours below 40), hourly | Hours only, exact, 15-minute minimum, no day conversion. Irregular-schedule volunteers fall here. |
| Day request | `counted_days × hours_per_day`, no further rounding. |
| Display | Day + hour + minute breakdown as today (`splitIntoDays`), with the divisor from the schedule rule above. |

### 3.4 Pending requests

`pending_hours` = sum of `quote()` for requests with status Pending on the same balance, excluding the one being edited. Computed at read time, so no new column is needed. Reserved hours reduce `projected_hours`. On accept they move into `used` (so the figure is counted once); on reject or cancel they drop out.

---

## 4. Historical data and recovery

1. **No existing request is recalculated.** `used` on every balance already includes approved requests priced by rule #2. Nothing in this contract touches those rows.
2. **Display of old rows.** A saved request is shown with the same legacy arithmetic that produced its deduction, so the screen still reconciles with `used`. The service exposes this as `quoteLegacy()` (a thin wrapper around today's accessor). Retire it only when the reconciliation below is authorized.
3. **Optional snapshot (decision D-5).** Storing `computed_hours` and `rule_version` on a request at creation would make pending and approved amounts immune to later changes of weekends, holidays or contract hours. This needs a schema change, so it is proposed, not assumed. Without it, pending is repriced on every read and an approved request is priced once at approval.
4. **Reconciliation, if HR wants it.** A dry-run-only report first (per balance: stored `used`, recomputed `used` under the new rule, difference), reviewed by HR, then a separate authorized command. It is not part of this task and nothing here runs it.
5. **Rollback.** The service is additive. Switching consumers back to the old accessors restores previous behavior, because no data changes.

---

## 5. Warning and error codes (stable, proposed)

| Code | Kind | When |
|---|---|---|
| `no_countable_days` | error | Every date is a weekend or holiday |
| `schedule_unknown` | error | `weekly_hours` is 0/missing, so a day cannot be priced |
| `exceeds_balance` | error for annual; warning for other types (D-6) | `projected_hours < 0` |
| `exceeds_type_max` | error | Per-request days over `leave_types.base_days` (current rule 5, kept) |
| `outside_working_hours` | error | Hourly request outside center open/close (current rule, kept) |
| `holiday_inside` | warning | One or more holidays were skipped |
| `december_restricted` | error | December request not marked exceptional (D-7); exceptional needs manager and HR approval |
| `no_leave_entitlement` | error | Service-agreement or draft contract: no balance, nothing deducted |
| `below_minimum` | error | Hourly request shorter than 15 minutes |
| `probation_excess` | warning | Probation: beyond 3 days is deducted from pay (policy). No amount is shown |
| `pending_reserved` | warning | Other pending requests already reserve hours |
| `balance_unknown_weekend` | warning | Fell back to the code default weekend because neither center nor governorate has one |

---

## 6. Rule table by case (AC-A3-D-01)

Legend: **Now** = what the code does today; **Contract** = proposed; **Status** = *Settled* (DEC-03), *Technical* (implementation choice, delegated) or *Open* (needs HR).

| Case | Now | Contract | Status |
|---|---|---|---|
| Annual, full days, 2-day weekend | Days via center weekends; annual check over-counts +1 | Count dates not weekend/holiday; `× hours_per_day` | Settled + Technical |
| Annual, hourly | No rounding; capped at half a day | Exact hours, 15-minute minimum; over 4 h = 1 day (full-time); cap removed | Settled (D-3a, D-3b) |
| Non-annual, days | Per-request vs `base_days` | Same check, plus balance check | Settled (D-6: warn) |
| Calendar-day exception: maternity (ids 4, 5) | Calendar days, no weekend/holiday skipping | Same | Preserved |
| Calendar-day exception: other types (sick, marriage, paternity, bereavement, unpaid) | Weekends skipped | Weekends and holidays skipped | Settled (D-2: maternity only) |
| Weekend, center set | `centers.weekends` | Same | Settled |
| Weekend, no center | Code default `0,6` | Weekend of any center of the volunteer's program in their governorate; code default only with `balance_unknown_weekend` warning | Settled (D-1); no new column |
| Holiday inside range | Counted as leave | Skipped, listed in `excluded_dates` | Settled (D-4: national + city + program) |
| Holiday on a weekend day | n/a | Counted once as excluded; no double reason | Technical |
| Five-day schedule | `/5` | `weekly_hours ÷ 5` | Preserved |
| Six-day schedule (Friday-only weekend) | `/5` → day too long (e.g. 9.6 h for 48 h/week) | `weekly_hours ÷ 6` | Technical; HR gave no fixed six-day case (D-8) |
| Fractional day length (e.g. 37.5 h/week) | Typed `int` accessor truncates | Float | Technical |
| Part-time | Same as full-time | Hours only, no day promotion | Settled (D-9: below 40 weekly hours) |
| `weekly_hours = 0` | Prices 0 h silently | `schedule_unknown` error | Technical |
| Pending requests | Ignored in checks | Reserve, per §3.4 | Settled |
| Irregular schedule (hours only in contract, ≤5 scattered days) | Priced as `/5` days | Hours only; no day request | Settled (D-8) |
| Service agreement | Exempt, no balance | `no_leave_entitlement`; manager approves the absence, HR informed; no deduction | Settled; **absence flow has no home yet** |
| December | None | Error unless marked exceptional and approved by manager and HR | Settled (D-7); needs an "exceptional" flag |
| Edit of accepted request | Read-modify-write, no re-check | One transaction, `new − old`, re-validate | Technical |
| Approved history | n/a | Unchanged | Settled |

---

## 7. Synthetic fixtures (proposed, expected values under the Contract)

Full-time unless stated: 40 h/week. Dates are in November 2026; no real employee, center or contract is involved. Center A weekend = Fri,Sat (`5,6`). Center B weekend = Sat,Sun (`0,6`).

| ID | Setup | Request | Expected | Today differs? |
|---|---|---|---|---|
| F1 | A, annual | Sun 1 – Thu 5 Nov | 5 days, 40 h | annual check says 6 |
| F2 | A, annual | Sun 1 – Sun 8 Nov (Fri 6, Sat 7 skipped) | 6 days, 48 h | annual check says 7 |
| F3 | B, annual | Mon 2 – Mon 9 Nov | 6 days, 48 h | annual check says 7 |
| F4 | A, holiday Wed 4 Nov | Sun 1 – Thu 5 Nov | 4 days, 32 h, `holiday_inside` | counts 5 |
| F5 | B | Sat 7 – Sun 8 Nov | error `no_countable_days` | counts 1 |
| F6 | A, annual | Tue 3 Nov, 09:00–12:20 | 3 h 20 min = 3.33 h exact (D-3a) | same |
| F7 | A, annual, 8 h day | 09:00–13:00 | 4 h (not over 4) | same |
| F8 | A, annual, 8 h day | 09:00–13:15 | 8 h (one full day, over 4 h) | form blocks (cap 4 h) |
| F9 | Part-time 20 h/week, 5 days → 4 h/day | 3 h | 3 h, no promotion | n/a |
| F10 | 48 h/week, weekend = Fri only → 6 days → 8 h/day | Sun 1 – Tue 3 Nov (3 days) | 24 h | `/5` gives 28.8 h |
| F11 | Maternity (calendar days), B | Mon 2 Nov – Tue 1 Dec | 30 calendar days, weekends and holidays included | same |
| F12 | Annual, eligible 96 h, used 16, pending 24 | 32 h | projected 24 h, `pending_reserved` | pending ignored |
| F13 | same as F12 | 64 h | projected −8 h, `exceeds_balance` (error for annual) | passes |
| F14 | `weekly_hours = 0` | any | error `schedule_unknown` | 0 h deducted |
| F15 | Accepted request, edit 2 days → 3 days | | `used += 8 h`, one transaction, re-validated | non-atomic |
| F17 | Part-time 20 h/week, irregular days | Day request | Not offered; hourly only (D-8, D-9) | priced as `/5` day |
| F18 | Service-agreement contract | Any | error `no_leave_entitlement`, nothing deducted | exempt, no balance |
| F19 | A, annual | Tue 3 Nov 09:00–09:10 | error `below_minimum` | accepted |
| F20 | A, annual | Tue 15 Dec, not marked exceptional | error `december_restricted` | accepted |
| F16 | Request approved before this contract | | `used` unchanged; displayed with legacy rule | n/a |

---

## 8. Decisions (all resolved 2026-10-20)

Recorded in [decisions.md](decisions.md), DEC-03 follow-up. Signed-off sheet: [a3-d-decision-sheet.md](a3-d-decision-sheet.md).

| ID | Answer |
|---|---|
| D-1 | Weekend = any center of the volunteer's program in their governorate. No new column. |
| D-2 | Maternity only is calendar days. |
| D-3a | Exact hours, 15-minute minimum. |
| D-3b | Hourly cap removed; full-time over 4 h = one day. |
| D-4 | National + center's city + program; recurring repeats yearly. |
| D-5 | Store computed hours on each request (schema change, technical lead to design). |
| D-6 | Non-annual over balance: warn. Annual: block. |
| D-7 | December blocked unless exceptional, with manager and HR approval (needs a flag). |
| D-8 | Irregular schedules exist: only weekly hours known, priced in hours only. |
| D-9 | Part-time = weekly hours below 40. |
| Service agreement | No leave balance; manager approves the absence, HR informed; no deduction. |

Open implementation items created by these answers (not part of A3-D): the schema for D-5 and D-7, and a no-balance absence flow for service agreements (`leave_balance_id` is required today).

---

## 9. Security and privacy

- The service takes a contract already authorized by the caller. The caller applies the active role and city scope (`LeaveRequest::manageableBy`, the `decide`/`update`/`view` policies, `authorizeActivePermission`). Menu visibility is not permission.
- The quote contains no salary, pay-tier or pay-per-day data. `User::canViewSalary()` is not involved and must not become involved: do not add a "cost of leave" field.
- Managers and colleagues see leave **type** (DEC-06) but not balances; `available_hours` and `projected_hours` are returned only to the owner and to HR within their city scope.
- `used` changes only inside a transaction with a row lock and a fresh authorization, as `submitDecision` already does. The edit-of-accepted path must follow the same pattern.

## 10. Evidence and limitations

| Evidence | Source |
|---|---|
| Source trace | `app/Models/LeaveRequest.php`, `app/Livewire/Forms/LeaveRequestForm.php`, `app/Livewire/Employee/Leave/MyLeaveRequestModule.php`, `app/Services/LeaveBalanceService.php`, `app/Models/LeaveBalance.php`, `app/Models/Employee.php` (`availableLeaves`) |
| Arithmetic differences | A throwaway PHP 8.5 script using the installed Carbon with synthetic dates (not committed; table in §1) |
| Settled policy | `docs/ux/decisions.md`, DEC-03 |

- No application test was run or added, and none is claimed. Existing leave tests (`LeaveRequestWorkflowTest`, `LeaveBalanceCarryOverTest`, `EmployeeProfileLeaveStatusTest`) were not re-run for this task.
- `leave_types.base_days` values and which ids are in production were not inspected (no data access). The type ids used are the `LeaveTypeCases` constants.
- The `holidays` table contents are unknown; only its schema and relations were read.
- Whether fractional `weekly_hours / 5` is truncated or raises a deprecation in `Contract::getWorkHourPerDayAttribute` was not executed; the `int` return type is the evidence.
- AC-A3-D-01 and -02 are met by this document; AC-A3-D-03 is met by the HR answers in §8. Review signed off on 2026-10-20.

## 11. Handoff

- **A3-R / future implementation:** build `LeaveRequestPricing` first, switch the preview, then validation, then deduction, in that order, each behind tests built from §7. Keep `quoteLegacy()` until reconciliation is decided.
- **C3-D / C5-D:** use `excluded_dates` and `pending_hours` for calendar and coverage; do not reimplement date arithmetic.
- **Next owner action:** plan the schema for D-5/D-7 and the service-agreement absence flow.
