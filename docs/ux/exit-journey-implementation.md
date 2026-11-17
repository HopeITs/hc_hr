# Exit journey and automatic deactivation: implementation notes

Status: **implemented, tests written but not run** (the CLI available while building was PHP 8.3; the app needs 8.5). Awaiting the test run on PHP 8.5, browser check and review (QA engineer + technical lead). The live migration is **not run**; it needs separate authorization (§6). Not marked done.
Date: 2026-10-30. Decision: DEC-09 increment 2 in [decisions.md](decisions.md). Design: [journeys.md](journeys.md) §3.3, §5. Sibling: [d4-i-implementation.md](d4-i-implementation.md) (onboarding), whose shape and guarantees this mirrors.

## 1. Starting state and decisions

| Item | Value |
|---|---|
| Revision | `rms-main` @ `cdce15e` |
| DEC-09 increment 2 | Build the exit journey now. **Last contract ended → automatic deactivation**, implementation default a **7-day grace period** after the end date, with **one heads-up** to city HR at the end. |
| Kept as is | Weekly `deactivate:employee` (§8 #1), onboarding journey, renewal behaviour, checklist workflow, AccessRevoker, DigitalIdManager. |

## 2. What was built

| Piece | File | Role |
|---|---|---|
| Config | `config/journeys.php` | `exit_grace_days` (env `EXIT_GRACE_DAYS`, default 7). |
| Table | `database/migrations/2026_10_30_000002_create_offboarding_journeys_table.php` | One row per departure, unique (`employee_id`, `contract_id`). Holds only `trigger`, `started_at/_by`, `exit_interview_held_at/_by`, `completed_at`. |
| Model | `app/Models/OffboardingJourney.php` | Nothing fillable (`$guarded = ['*']`); trigger constants. |
| Domain service | `app/Services/OffboardingJourneys.php` | **The only writer**, and the departure rule: `departure`, `startForDeparture`, `markExitInterviewHeld`, `reopen`, `deactivateDeparted`, `settle`, `isCalledOff`, `steps()` (read model). |
| Start hook | `app/Observers/ContractObserver.php` (`updated` → `departureTrigger`) | E1 (auto-renew switched off) and E4 (termination recorded) start the journey when it is the volunteer's last contract. |
| Daily command | `app/Console/Commands/DeactivateDepartedEmployees.php` (`employees:deactivate-departed [--dry-run]`, daily **21:45**, after `deactivate:contract` 21:30 and auto-renewals 21:35) | Starts the journey for contracts that simply ran out, sends the heads-up, deactivates after the grace period, settles completion. |
| Heads-up | `app/Notifications/LastContractEnded.php` | In-app, once, to city HR. |
| Relations | `app/Models/Employee.php` | `offboardingJourneys()`, `latestOffboardingJourney()`. |
| Panel | `app/Livewire/Employee/OffboardingJourneyPanel.php`, `resources/views/livewire/employee/offboarding-journey-panel.blade.php`, mounted in `employee/form.blade.php` under the onboarding panel | Edit page only. Shown only when the employee has an exit journey. |
| Strings | `resources/lang/{en,ar}/offboarding.php` | New files (no edit to `employees.php`). |
| Access classification | `config/access-control.php` | §4. |
| Tests | `tests/Feature/OffboardingJourneyTest.php` | §7. |

### 2.1 Who has left (the departure rule)

A real contract (type not Draft) **carries on** when it is:

- **current or future**: not terminated, and either starts after today (whatever its active flag) or is active and not past its end date; or
- **awaiting auto-renewal**: ended, `auto_renew` on and allowed (not terminated, not a trial), and its successor (same position, starting the day after the end, the rule `ContractAutoRenewalService` uses) not made yet. Normally the 21:35 run has made it already; this only matters on a night that run failed.

A complete employee with real contracts and **none that carries on** has left. Their last contract is the one with the latest **end**: the termination date when one is recorded (or the end date if that came first), otherwise the end date; a contract switched off early without a date stopped on the day it was saved (`updated_at`). Deactivation is due on **end + `exit_grace_days`**.

### 2.2 Step → authoritative record

| Step | State shown | Owner | Authoritative record | Journey stores it? |
|---|---|---|---|---|
| 1 Equipment returned | Done / Needs HR (an item has no return requested) / Waiting (return requested, on the receiver); **Late** after the contract end | HR, or the equipment receiver | `checklists`: no `delivered` row without `returned_status = received` (a finalized cycle deletes the row) | No |
| 2 Contract ended | Done / Waiting | Automatic | the journey's contract (`is_active`, `end_date`, `terminated_at`) | Link only |
| 3 Deactivated | Done / Waiting (after 2) / Blocked + **Late** (past end + grace and still active) | Automatic | `employees.is_active` | No |
| 4 Access and card closed | Done / Waiting (after 3) / Blocked + **Late** (deactivated but a role is active or the card is on, with which) | Automatic | account `role_user.is_active` all false; `employees.digital_id_enabled = false` | No |
| 5 Exit interview (optional) | Done / Needs HR | HR | `exit_interview_held_at/_by` | **Yes, the only step it stores** |

- **Due dates:** equipment and contract = the contract's end; deactivation and access = end + grace.
- **Complete** (`completed_at`) when steps 1–4 are done; the interview is optional. Set the first time it is seen complete (by an HR action or the daily run), and **sticky**: a later re-hire does not reopen a finished departure.
- **Called off:** an unfinished journey whose contract rolls over again (HR switched auto-renew back on) or whose volunteer has another contract that carries on. The panel says so and takes no marks. Computed, never stored; switching auto-renew off again on the same contract lands on the same row.

### 2.3 The daily run and the heads-up

For each active, complete employee with real contracts and none current or future (SQL), then the full rule above (PHP):

1. Start the journey (`trigger = contract_ended`) if E1/E4 did not already; same row if they did.
2. Before end + grace: **one** heads-up to city HR (`City::hrRolesForSlug`, HR-SYR when the city has none; active profiles only): "X's last contract ended on D; they will be deactivated on D+grace unless a new contract starts." Claimed in `NotificationDeliveryLedger` (kind `last-contract-ended`, subject = the contract, day = its end date), so later runs, retries and a second worker never send it again. A failed send releases the claim for the next run.
3. From end + grace: `deactivateDeparted` locks the employee row, re-reads the rule under the lock (a contract saved meanwhile wins), and saves `is_active = false`, the same save HR's toggle makes, so `EmployeeObserver` disables the card and runs `AccessRevoker` (sessions, remember token, roles, logged under `security`). Logged under `offboarding` as `employee_deactivated` with `reason = last_contract_ended`, contract, end date, grace days and journey; no causer (the scheduler). Also a `Log::info` line.
4. Every open journey is checked for completion.

`--dry-run` lists who would be warned or deactivated and changes nothing. Run it on production before the first scheduled night (§6).

## 3. Idempotency and concurrency

| Case | Behaviour |
|---|---|
| Auto-renew off, on, off again; termination saved twice; daily run every night | One row: unique (`employee_id`, `contract_id`); `startForDeparture` returns the existing row; a concurrent insert that loses the unique race returns the winner's row. One `offboarding_started` entry. |
| Not the last contract (another one carries on), a Draft reservation, a contract that still rolls over | No journey. The daily run picks the departure up when the last one ends. |
| Run twice the same night / after deactivation | The employee is no longer a candidate; `deactivateDeparted` re-checks under a row lock and returns false. One `employee_deactivated`, one `access_revoked`. |
| A contract entered after the grace period but before 21:45 | The re-check under the lock sees it: no deactivation. |
| Exit interview double click / two HR officers | Transaction + `lockForUpdate` on the journey row + expected state; the loser changes nothing and is told "Nothing changed". |
| Called-off journey | Marks refused (no change). |

## 4. Security and audit

- **Authority:** `mount` and every action call `Gate::authorize('update', $employee)` → `EmployeePolicy::update` (active role holds `employees.update` **and** the volunteer is in the active city). Re-checked per action; HR of another city gets 403. The employee id is `#[Locked]`.
- **Access-control classification** (`config/access-control.php`):
  - `livewire` → `employee.offboarding-journey-panel`: `markExitInterviewHeld`, `confirmReopen`, `reopen` → `employees.update`;
  - `livewire_exempt` → `refreshJourney` (re-renders after the edit page saves; writes nothing).
- **Undo** (`confirmReopen` → `reopen`) asks first and only accepts `interview`; anything else is 404.
- **Audit:** activity log `offboarding`, with `employee_id` and the actor: `offboarding_started` (contract, trigger; causer = HR who stopped auto-renew or terminated, none for the scheduler), `exit_interview_held`, `step_reopened`, `offboarding_completed`, and `employee_deactivated` (on the employee, reason). Access revocation stays in `security`, the card in the digital-ID log.
- **Pay:** not read, not shown, not in the notice.
- **The one automated employment decision** is deactivation after the grace period, by product-owner decision. The journey itself never deactivates, revokes or changes a contract: the panel only reads those steps and links to where HR acts.

## 5. What did not change

Weekly `deactivate:employee`, `deactivate:contract`, auto-renewal, `ChecklistWorkflow` (E2 still starts return cycles on E1), `AccessRevoker`, `DigitalIdManager`, `EmployeeObserver`, the onboarding journey, `employees.php` strings, existing rows.

## 6. Rollout and recovery

| Item | Value |
|---|---|
| Migration | **Additive**: creates `offboarding_journeys` only. No backfill. |
| First night | The command deactivates every active volunteer whose last contract ended ≥ grace days ago, including long-ago ones nobody deactivated by hand. **Run `php artisan employees:deactivate-departed --dry-run` on production first** and review the list with HR. |
| Turning it off | Remove the 21:45 line in `routes/console.php` (or set `EXIT_GRACE_DAYS` very high). |
| Recovery from a wrong deactivation | HR toggles the employee back on; the login works again on sign-in; the card stays off until HR enables it (DEC-02). Then enter the missing contract, or the next run deactivates again. |
| Rollback | `migrate:rollback --step=1` drops the table; the `offboarding` log entries remain. |
| Foreign keys | Employee deleted → journeys deleted (cascade). Contract deleted → `contract_id` null. Users deleted → the "by" columns null. |
| UI-only revert | Remove the `<livewire:employee.offboarding-journey-panel>` line from `employee/form.blade.php`. |

## 7. Verification

| Item | Result |
|---|---|
| Test file | `tests/Feature/OffboardingJourneyTest.php`, 18 tests |
| Tests | **Not run** (PHP 8.3 CLI in the build environment). Run: `php artisan test --filter=OffboardingJourneyTest`, then `php artisan test --filter='LivewireMutationCoverageTest|OnboardingJourneyTest|ContractAutoRenewalWorkflowTest|ContractRenewalTest|ContractCreatePerformanceTest'` |
| Full suite | Not run. |
| Pint / `php -l` | Pass on every new or changed PHP file |
| Browser (EN/AR, desktop/phone) | Not done |

Coverage: deactivation only after the grace period (and from config); never with a current, future or auto-renewed (pending or made) successor; a late contract wins under the lock; AccessRevoker and the card run; dry run changes nothing; heads-up once to city HR only, with both dates; E1 / E4 / ran-out each start one journey, not when another contract carries on; every step state from its own record (equipment todo → waiting → done, contract, deactivation late, access done and problem), completion; interview mark and undo; called off; another city's HR gets 403; causer recorded; repeats write once.

## 8. Open points and limits

| # | Item | Owner |
|---|---|---|
| 1 | **Check:** the weekly `deactivate:employee` filters with `whereDoesntHave('contracts')`, and `Employee::contracts()` is scoped `activeNow()`. So it deactivates every active employee with no *currently active* contract (including one whose last contract ended days ago, inside the grace period, or whose only contract starts in the future), not only those with no contracts at all as journeys.md §3.3 E6′ says. **Resolved 2026-10-30 (product owner): the weekly sweep is unscheduled**; `employees:deactivate-departed` is the only automatic deactivation. Active employees with no contracts at all are no longer swept automatically. | Done |
| 2 | Journeys are not a work-inbox source yet (DEC-09 increment 2 wants exit steps there). | Next increment (WorkInbox owner) |
| 3 | A volunteer with two concurrent contracts: stopping auto-renew on one starts nothing while the other carries on; the journey starts from the daily run when the last one ends. | Accepted |
| 4 | An unfinished journey (e.g. equipment never returned) shows as "called off" if the person is later re-hired. | Accepted |
| 5 | Completion after an equipment return or a manual access fix is recorded at the next daily run or HR action, not instantly (the panel's step states are always live). | Accepted |
| 6 | Termination seat question (journeys.md §7 #1): **fixed 2026-10-30** in `ContractObserver`; see there. | Done |
| 7 | Browser check not done. | QA |
