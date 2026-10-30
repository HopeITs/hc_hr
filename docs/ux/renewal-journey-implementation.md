# Renewal journey (DEC-09 increment 2): implementation notes

Status: **implemented, tests written but not run** (the CLI here is PHP 8.3; the framework needs 8.4+). Awaiting a test run on PHP 8.5, a browser check and review (QA engineer + technical lead). The live migration is **not run**; it needs separate authorization (§6). Not marked done.
Date: 2026-10-30. Decision: [decisions.md](decisions.md) "DEC-09 increment 2" (product owner: build the renewal journey now). Map: [journeys.md](journeys.md) §3.2. Pattern mirrored: [d4-i-implementation.md](d4-i-implementation.md) (onboarding).

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision | `rms-main` @ `cdce15e` |
| DEC-09 increment 2 | Renewal and exit journeys built now, as linked journeys like onboarding. Exit journey and automatic deactivation are a separate increment (not touched here). |
| R1–R4b | [journeys.md](journeys.md) §3.2: expiry warnings (R1), HR decision (R2), contract end (R3), auto-renewal (R4a), manual renewal (R4b). Reused, not changed in behaviour, except the leave-balance fix (§3). |
| D4-I | Onboarding journey: same shape (one additive table, one service as the only writer, a panel on the edit page, `lockForUpdate` + expected-state checks, activity log with causer). |

## 2. What was built

| Piece | File | Role |
|---|---|---|
| Table | `database/migrations/2026_10_30_000101_create_renewal_journeys_table.php` | One row per expiring contract (`contract_id` **unique**). Holds only the decision (`decision`, `decided_by`, `decided_at`, `decision_switched_auto_renew`), plus `employee_id`, `started_at`, `completed_at`. |
| Model | `app/Models/RenewalJourney.php` | Nothing fillable (`$guarded = ['*']`); `decisionIsLate()`. |
| Domain service | `app/Services/RenewalJourneys.php` | **The only writer.** `startDue`, `startForContract`, `decide`, `undo`, `settleOpen`, and `steps()` (read model for the panel), `successorOf()`. |
| Shared auto-renew path | `app/Services/ContractAutoRenewalService.php` → `setAutoRenew(Contract, bool, User)` | Extracted from `ContractsTable::toggleAutoRenewConfirmed`. Re-checks `ContractPolicy::manageAutoRenew` for the actor, locks the contract row, writes through Eloquent (so `ContractObserver` still forces trial/terminated off and starts equipment returns). Used by the contracts list toggle **and** the journey's "stop". |
| Daily command | `app/Console/Commands/TrackRenewalJourneys.php` (`renewals:track-journeys`, daily 21:45 in `routes/console.php`) | Starts due journeys, then settles open ones the system finished (after the 21:35 auto-renewal). |
| Panel | `app/Livewire/Employee/RenewalJourneyPanel.php`, `resources/views/livewire/employee/renewal-journey-panel.blade.php` | On the employee edit page, under the onboarding panel. One block per open journey (and those finished in the last 30 days). |
| Strings | `resources/lang/{en,ar}/renewal.php` | New files, EN and AR, same keys. |
| Access classification | `config/access-control.php` | §4. |
| R1 ledger key | `SendContractExpirationWarnings::LEDGER_KIND` | Made public so the journey reads the same ledger kind. No behaviour change. |
| Leave exemption read | `LeaveBalanceService::exemptFromLeave()` | Public wrapper over the existing private rule, so "no balances" on a service contract reads as expected, not as a gap. |

### 2.1 Step → authoritative record

| # | Step | States shown | Owner | Authoritative record | Journey stores it? |
|---|---|---|---|---|---|
| 1 | Expiry warning to the manager (R1) | Done (last sent date, count) / Waiting / Not needed (auto-renew off) / Blocked (no direct manager with an account) | Automatic | `notification_deliveries` rows of kind `contract-expiry-warning` for this contract, `delivered_at` set | No |
| 2 | Decide: renew or stop (R2) | Done / Needs HR / Not needed (contract terminated); **Late** tag | HR | `renewal_journeys.decision/_by/_at` | **Yes (the one new human record)** |
| 3 | New contract in place (R4a/R4b) | Done / Waiting (after 2, or auto-renew will create it on end + 1) / Needs HR (renew decided, auto-renew off: renew by hand) / Not needed (stopped, terminated) | Automatic, or HR when auto-renew is off | the first later `contracts` row for the same employee and position (not a Draft) | No |
| 4 | Leave balances on the new contract | Done (or exempt: service contract) / Waiting (after 3) / Blocked (new contract has none) / Not needed | Automatic | `leave_balances` of the successor | No |

- **Due date** of the decision = the contract's `end_date`, read live (an edited end date moves it).
- **Late** = no decision and today ≥ `end_date − 7 days` (`RenewalJourneys::LATE_DAYS`), app timezone. Shown as `<x-tag variant="late">`.
- **Completion** (`completed_at`), recomputed on every journey write and by the daily command: decision "stop" → finished (outcome `stopped`; the exit journey takes over); contract terminated → finished (`terminated`); decision "renew" + successor + its leave balances (or exempt) → finished (`renewed`). An undo reopens it.

### 2.2 The decision

| Decision | Effect | Refused when |
|---|---|---|
| **Renew** ("Let it renew") | Records the decision only. Auto-renew stays as it is: on → the 21:35 run creates the successor; off → step 3 shows "Renew by hand" linking to `contracts.renew` (shown only when `ContractPolicy::renew` allows). | A decision already exists; the contract is terminated. |
| **Stop** (with confirmation) | `setAutoRenew(contract, false, actor)`, the same path as the contracts list toggle; the observer starts equipment returns and notifies receivers. `decision_switched_auto_renew` records whether this decision was what switched it off. | A decision already exists; terminated; a successor already exists. |
| **Undo** (with confirmation) | Clears the decision. If the "stop" switched auto-renew off, switches it back on through the same path; if auto-renew was already off, leaves it off. Equipment returns already started stay open. | No decision; a successor already exists. |

## 3. The leave-balance gap: real, fixed

**Finding.** `ContractCreate::submitOnce()` and `ContractRenewModal` call `LeaveBalanceForm::store()` → `LeaveBalanceService::create()` after saving a contract. `ContractAutoRenewalService::renewIfNeeded()` replicated the contract and copied its pay tiers but never called `LeaveBalanceService`, and nothing else does on that path (no observer creates balances; `leaves:backfill` is manual). So **every auto-renewed contract started with no leave balances**: no annual, sick or unpaid entitlement until someone ran `leaves:backfill`. The existing auto-renewal test did not assert balances, which is why it went unnoticed.

**Fix (minimal).** `renewIfNeeded()` now calls `LeaveBalanceService::create($renewal)` after copying the pay tiers, inside the same transaction, in a **savepoint**. If the balance cannot be priced (for example no annual-leave rule on file), the exception is reported and the renewal still stands, so one bad row never blocks the night's run; `leaves:backfill` fills it later and the journey shows step 4 as Blocked meanwhile. A rerun creates nothing (the successor check returns early), so no second set.

**Tests.** `test_an_auto_renewed_contract_gets_its_leave_balances`, `test_an_auto_renewal_whose_leave_cannot_be_priced_still_renews`, and the end-to-end `test_renew_followed_by_the_auto_renewal_completes_the_journey`.

**Not backfilled.** Contracts auto-renewed before this fix still have no balances. Run `php artisan leaves:backfill --dry-run --active` on production to list them, then without `--dry-run` (it only touches contracts with no balances).

## 4. Idempotency, concurrency, security and audit

| Case | Behaviour |
|---|---|
| Command run twice, retried, or two workers | `insertOrIgnore` on the unique `contract_id`: one journey per contract, one `renewal_started` log. |
| Missed days | The window is "ends within 30 days and not yet ended", so the next run catches up. |
| Trial, terminated, Draft reservation | Never started (`Contract::canAutoRenew`, the same rule the observer and the auto-renewal use). |
| Already renewed by hand before the window | Not started: nothing left to decide. |
| Double click / two HR officers | Every write locks the journey row and moves only from the expected state; the loser changes nothing and gets "Nothing changed". `setAutoRenew` also locks the contract row and compares. |

- **Authority:** `mount` runs `EmployeePolicy::update` (same as the edit page). Every action runs `Gate::authorize('manageAutoRenew', $contract)` on the journey's contract: HR active role, `contracts.update`, contract visible in the active city. Re-checked on each call, so a role or city switch since the page loaded counts. HR of another city gets 403; a role without `contracts.update` and a non-HR role with it get 403. The journey must belong to the panel's employee (404 otherwise). The employee id is `#[Locked]`.
- **What is shown:** only journeys whose contract passes `manageAutoRenew` for the viewer; anyone else sees no section. The edit page includes the panel only for an active HR role with `contracts.update`.
- **Access-control classification** (`config/access-control.php`): `livewire` → `employee.renewal-journey-panel`: `decideRenew`, `confirmStop`, `stop`, `confirmUndo`, `undo` → `contracts.update`; `livewire_exempt` → `refreshJourneys` (re-render only).
- **Pay:** never read. The view gets the journey, the position title and dates; no figure is loaded into the view or the Livewire payload. A test asserts the salary is not in the HTML.
- **Audit:** activity log `renewal`, on the journey, with `contract_id`, `employee_id` and the actor: `renewal_started` (system, end date), `decision_recorded` (decision, `auto_renew_switched_off`), `decision_undone` (from, `auto_renew_restored`), `renewal_completed` (outcome), `renewal_reopened`. The contract's own `contract` log also records the `auto_renew` change as before.
- **No employment decision is automated:** the journey never creates, ends or terminates a contract. "Stop" is HR's decision and changes exactly what the contracts list toggle changes.

## 5. What did not change

Expiry warning rules and recipients (R1), contract end (R3), the auto-renewal rules (R4a) except leave balances, manual renewal (R4b), capacity checks, onboarding journey behaviour, exit journey and automatic deactivation (another increment), work inbox.

`ContractsTable::toggleAutoRenewConfirmed` now writes through `setAutoRenew`; its own Gate and `canAutoRenew` checks are unchanged, so it behaves as before (plus a row lock).

## 6. Rollout and recovery

| Item | Value |
|---|---|
| Migration | **Additive**: creates `renewal_journeys` only. No backfill: the first run of `renewals:track-journeys` starts journeys for contracts ending in the next 30 days. |
| Rollback | `migrate:rollback --step=1` drops the table; nothing else reads it. Decisions are lost; the `renewal` log entries and the `auto_renew` values on contracts remain. |
| Foreign keys | Contract or employee deleted → journey deleted (cascade). User deleted → `decided_by` null. |
| Scheduler | New 21:45 entry, after the 21:35 auto-renewal. |
| Live migration | **Not run.** Needs separate authorization; run with the deploy. |
| UI-only revert | Remove the `<livewire:employee.renewal-journey-panel>` block from `employee/form.blade.php`; data is kept. |

## 7. Verification

| Item | Result |
|---|---|
| Test file | `tests/Feature/RenewalJourneyTest.php`, 21 tests |
| Tests | **Not run here** (CLI is PHP 8.3). Run on PHP 8.5: `php artisan test --filter='RenewalJourneyTest\|ContractAutoRenewalWorkflowTest\|LivewireMutationCoverageTest\|A4BilingualTest\|ContractRenewalTest'` |
| Full suite | Not run. |
| Pint | Passes on every new and changed PHP file. |
| `php -l` | Passes on every new and changed PHP file. |
| Browser (EN/AR, desktop/phone) | **Not done** |

## 8. Open points and limits

| # | Item | Owner |
|---|---|---|
| 1 | Journeys are not in the work inbox or a dashboard tile yet (DEC-09 increment 2 says they should be; the inbox is another agent's work). | Next increment |
| 2 | A contract that auto-renewed with **no** decision recorded keeps its journey open and Late until HR records "renew". Acceptable (the decision is the human record), or the settle step could close it as "renewed without a decision". | Product owner |
| 3 | Undoing a "stop" after the end date switches auto-renew back on, so the next 21:35 run renews it from end + 1 (what would have happened). Undo is refused once a successor exists. | Product owner (accept, or refuse undo after the end date) |
| 4 | Auto-renewed contracts from before this fix have no leave balances; run `leaves:backfill` (§3). | Ops |
| 5 | The successor rule is "same employee and position, starting later". A renewal onto a different position is not recognised as the successor; the journey then waits on HR. | Product owner |

## 9. Acceptance

| Item | Status | Evidence |
|---|---|---|
| Journey starts once at 30 days, not for trial/terminated | Implemented, test written | §2, §4; `test_the_journey_starts_once_…` |
| Stop through the shared path, same permission rule | Implemented, test written | §2.2; `test_the_stop_decision_…`, authority tests |
| Renew + auto-renewal completes the journey | Implemented, test written | §2.1; `test_renew_followed_by_the_auto_renewal_…` |
| Undo, causer, idempotency, no salary | Implemented, tests written | §4 |
| Leave-balance gap | Verified real, fixed, tests written | §3 |

**Formal review (QA engineer + technical lead): pending.** Not marked done.
