# D4-I · Onboarding journey (first slice): implementation notes

Status: **implemented, targeted tests passing (79/79), awaiting full suite, browser check and review** (QA engineer + technical lead). The live migration is **not run**; it needs separate authorization (§6). Not marked done.
Date: 2026-10-30. Task: D4-I in [ux-prompts-v3.md](ux-prompts-v3.md) (R-21). Design and slice contract: [journeys.md](journeys.md) §6. Consumers: final handoff (G-R).

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision | `rms-main` @ `2798d70` (code). These notes are not committed. |
| DEC-09 + detail | [decisions.md](decisions.md), settled 2026-10-16 and 2026-10-30: orientation date + held, owner city HR; digital card at the first contract, HR marks the printed card handed over; orientation due 14 days after the first contract's start, Late tag + one reminder. |
| D4 | [journeys.md](journeys.md): maps, consequential actions, slice contract. |
| B5 | [b5-implementation.md](b5-implementation.md): draft / awaiting contract / active, `EmployeeActivation`. Reused, not changed in behaviour. |
| C3 | Work inbox. Not extended here (§8). |

## 2. What was built

| Piece | File | Role |
|---|---|---|
| Table | `database/migrations/2026_10_30_000001_create_onboarding_journeys_table.php` | One row per volunteer (`employee_id` unique). Holds only orientation (due, scheduled, held + who) and printed card (handed over + who), plus `started_at`, `completed_at`, the starting `contract_id`. |
| Model | `app/Models/OnboardingJourney.php` | Nothing fillable (`$guarded = ['*']`); `orientationIsLate()`. |
| Domain service | `app/Services/OnboardingJourneys.php` | **The only writer.** `startForContract`, `scheduleOrientation`, `completeOrientation`, `markCardHandedOver`, `reopen`, and `steps()` (read model for the panel). |
| Start hook | `app/Services/EmployeeActivation.php` | When the first real contract activates a complete inactive volunteer, starts the journey **inside the same transaction**, with the employee row locked. |
| Relation | `app/Models/Employee.php` | `onboardingJourney()` (has one). |
| Panel | `app/Livewire/Employee/OnboardingJourneyPanel.php`, `resources/views/livewire/employee/onboarding-journey-panel.blade.php` | On the employee edit page (not on create). Five steps, each with state, owner, due date, "After" (blocking step) and the one action HR has now. |
| Reminder | `app/Console/Commands/SendOrientationReminders.php` (`onboarding:send-orientation-reminders`, daily 08:10 in `routes/console.php`), `app/Notifications/OrientationOverdue.php` | One in-app notice to city HR per overdue orientation. |
| Strings | `resources/lang/{en,ar}/employees.php` → `journey.*` | EN and AR. |
| Access classification | `config/access-control.php` | §4. |

### 2.1 Step → authoritative record

| Step | State shown | Owner | Authoritative record | Written by | Journey stores it? |
|---|---|---|---|---|---|
| 1 Complete the record | Done / Needs HR | HR | `employees.record_status` | `EmployeeCreate` / `EmployeeEdit` (B5) | No |
| 2 First contract | Done / Needs HR / Waiting (after 1) | HR | first real `contracts` row (journey keeps its id) | `ContractCreate` | No (link only) |
| 3 Login account | Done / Waiting (after 2) / Blocked (email in use) | Automatic | `users.employee_id` | `EmployeeActivation` | No |
| 4 ID card | Done / Needs HR / Waiting / Blocked (digital card not valid, with the reason) | HR | digital: `employees.digital_id_enabled` + `DigitalIdManager::cardCode`; printed: `card_handed_over_at/_by` | `DigitalIdManager`; `OnboardingJourneys::markCardHandedOver` | Printed part only |
| 5 Orientation session | Done / Needs HR / Waiting; **Late** tag when past due | HR | `orientation_scheduled_for/_by`, `orientation_completed_at/_by`, `orientation_due_on` | `OnboardingJourneys` | Yes |

- The panel also shows steps 1–2 for a draft or a volunteer awaiting a contract (no journey row yet), so HR sees the whole path from the start.
- **Staff without a journey see no panel**: existing active staff, former staff, and anyone re-hired (re-hired staff are re-activated by hand before their contract, so the start hook never runs for them).
- `completed_at` is set when account, printed card and orientation are all done, and cleared again by an Undo.

### 2.2 Due date and the reminder

- `orientation_due_on` = first contract `start_date` + **14 days** (`OnboardingJourneys::ORIENTATION_DAYS`), fixed when the journey starts.
- **Late** = not held and the due date is before today (app timezone). Shown as `<x-tag variant="late">`; the step's status stays icon + words.
- **Reminder:** the daily command picks journeys not held, past due, for **active** employees. Recipients: HR roles for the volunteer's city (`City::hrRolesForSlug`), or HR-SYR when the city has none; only users whose employee profile is active.
- **Once only:** each send is claimed in `NotificationDeliveryLedger` under kind `orientation-overdue`, keyed to the user, the journey and the journey's start day, so later runs, retries and a second worker never send again. A failed send releases the claim and is retried the next day.
- The notice holds the volunteer's name, the due date and a link to the edit page. No pay, no contract details. In-app (`database`) only.

## 3. Idempotency and concurrency (AC-D4-I-02)

| Case | Behaviour |
|---|---|
| Contract save retried, second contract, contract renewal | `startForContract` returns the existing journey; activation already ran once (B5). One journey per employee is also enforced by the unique `employee_id`. |
| Draft-type reservation, or the employee already had a real contract | No journey. |
| Double click / two HR officers at once | Every write runs in a transaction with `lockForUpdate` on the journey row and only moves from the expected state; the loser changes nothing and gets "Nothing changed: this step was already in that state." |
| Same orientation date saved again | No change, no log entry. |
| "Held" before the session date, or with no date | Refused (no change). |
| "Card handed over" while the digital card is not valid | Refused. |
| Account, contract, card | Never created or changed by the journey. Reused from their own records. |

## 4. Security and audit (AC-D4-I-03)

- **Authority:** `mount` and every action call `Gate::authorize('update', $employee)` → `EmployeePolicy::update`: active role holds `employees.update` **and** the volunteer is in the user's active city. Re-checked on every action, so a role or city switch since the page loaded counts. HR of another city gets 403. The employee id is `#[Locked]`.
- **Access-control classification** (`config/access-control.php`):
  - `livewire` → `employee.onboarding-journey-panel`: `scheduleOrientation`, `completeOrientation`, `markCardHandedOver`, `confirmReopen`, `reopen` → `employees.update`;
  - `livewire_exempt` → `refreshJourney` (re-renders after the edit page saves; writes nothing).
- **Undo** (`confirmReopen` → `reopen`) asks first (B0 confirm dialog) and only accepts `card` or `orientation`; anything else is 404.
- **Audit:** activity log `onboarding`, on the journey, with `employee_id` and the actor: `onboarding_started` (contract id), `orientation_scheduled` (from → to), `orientation_completed` (held on), `card_handed_over`, `step_reopened` (step). The mark and its undo both stay.
- **Contract links** in the panel appear only with `contracts.view` / `contracts.create`; the links are not permissions, the target pages authorize again.
- **Pay:** not read, not shown, not in the notice.
- **No employment decision is automated:** the journey never activates, deactivates, creates accounts or changes contracts. Those stay with B5's activation (triggered by HR's contract) and the existing flows.

## 5. What did not change

B5 activation rules, contract composer, capacity checks, checklist workflow, digital ID rules, renewal and exit behaviour, existing employees' rows.

## 6. Rollout and recovery

| Item | Value |
|---|---|
| Migration | **Additive**: creates `onboarding_journeys` only. No backfill: **existing staff get no journey**, so nobody already working is shown an unfinished one. |
| Rollback | `migrate:rollback --step=1` drops the table; nothing else reads it. Journey history (dates, who) is lost on rollback; the `onboarding` activity-log entries remain. |
| Foreign keys | Employee hard-deleted (draft discard) → journey deleted (cascade). Contract deleted → `contract_id` null; the panel then reads the oldest real contract. Users deleted → the "by" columns null. |
| Live migration | **Not run.** Needs separate authorization; run with the deploy. |
| Scheduler | The new 08:10 entry runs only where the scheduler runs; with no journeys it sends nothing. |
| UI-only revert | Removing the `<livewire:employee.onboarding-journey-panel>` line from `employee/form.blade.php` hides the panel; data is kept. |

## 7. Verification

> **Placeholder: to be filled by the parent agent.** Tests are being written separately; nothing below is claimed yet.

| Item | Result |
|---|---|
| Test file(s) | `tests/Feature/OnboardingJourneyTest.php`, 19 tests |
| D4-I tests | **Passed, 2026-10-30, on Laragon (PHP 8.5):** `OnboardingJourneyTest` 19/19, `EmployeeOnboardingTest` 28/28, `RecruitmentHiringConversionTest` 32/32. Total 79 passed, 579 assertions, 101.9 s. |
| Full suite | **Not run yet.** Run `php artisan test` on PHP 8.5 before review. |
| Pint | `pint --test` passes on every new PHP file and the test |
| Migration forward / back / forward on a synthetic copy | _not run_ |
| Browser (EN/AR, desktop/phone) | **not done** |

AC → tests mapping: AC-D4-I-01 first contract starts one journey (due +14 days), step states for draft / awaiting / started, completion and undo; AC-D4-I-02 retried activation, second contract and repeated actions write once, a Draft reservation starts nothing; AC-D4-I-03 another city's HR gets 403 on every action, causer recorded, no completion before the session date, no card while the digital card is invalid; reminder sent once to city HR only.

Known gap: if the account was missing (work email taken) and HR creates it later from Access Management, `completed_at` is set only at the next journey action.

## 8. Open points and limits

| # | Item | Owner |
|---|---|---|
| 1 | No work-inbox item or dashboard tile for journeys yet; HR finds them on the employee page and through the reminder. | Next increment |
| 2 | No "Onboarding" view in the employee list (who is mid-journey, who is late). | Next increment |
| 3 | Browser check not done (EN/AR, desktop/phone, keyboard, screen reader). | QA |
| 4 | **Fixed 2026-10-30:** a recruitment hire now starts the journey (`RecruitmentHiringConversionService::run`, after the contract), and `RecruitmentHiringConversionTest` asserts one journey even across retries. Passed on PHP 8.5 (§7). | Done |
| 5 | A journey stays visible after the volunteer is deactivated; the card step then shows Blocked and no reminder is sent. Acceptable for now; revisit with the exit journey. | Product owner |
| 6 | A backdated first contract can start a journey that is already Late; the one reminder then goes out at the next run. | Product owner (accept or set a floor) |
| 7 | Renewal and exit journeys deferred ([journeys.md](journeys.md) §5). | Product owner |

## 9. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-D4-I-01** The selected slice works end to end with one owner/status per step. | **Implemented, awaiting tests/review** | §2, §2.1, §2.2; tests §7 (pending) |
| **AC-D4-I-02** Existing records are reused and retry does not duplicate domain writes. | **Implemented, awaiting tests/review** | §2.1 "Journey stores it?", §3; tests §7 (pending) |
| **AC-D4-I-03** Consequential transitions require the chosen human authority and retain an audit trail. | **Implemented, awaiting tests/review** | §4; tests §7 (pending) |

**Formal review (QA engineer + technical lead): pending.** D4-I is **not** marked done.
