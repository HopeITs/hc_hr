# Journey steps in the work inbox: implementation notes

Status: **implemented, tests written, not run** (the CLI here is PHP 8.3, the app needs 8.5). Not marked done.
Date: 2026-10-30. Decision: [decisions.md](decisions.md) "DEC-09 increment 2": unfinished journey steps that wait on HR show in HR's **work inbox**, with the Late tag. Contract: [approval-source-contract.md](approval-source-contract.md) §3, §5. Journey: [d4-i-implementation.md](d4-i-implementation.md).

## 1. What HR sees

One row per volunteer whose onboarding journey has a step waiting on HR, under a new **Journeys** filter of the inbox (`/inbox?show=journey`):

| Step | Shown when | Status |
|---|---|---|
| Login account missing | the volunteer has no `users` row | Blocked (dashed, `x-status` tone `stop`) |
| Hand over the printed ID card | `card_handed_over_at` is null | Needs HR (ink outline, tone `attn`) |
| Schedule / Record the orientation session | `orientation_completed_at` is null (schedule while no date, record once dated) | Needs HR |

The row shows the steps, **Due by** the orientation due date while the session is not held, the **Late** tag (`<x-tag variant="late">`, `OnboardingJourney::orientationIsLate()`), and one button, **Open employee page** (`employees.edit`), where the journey panel acts and re-authorizes. The inbox has **no journey action**: nothing is written from it, and no public Livewire method was added (`setFilter` already existed; it accepts `journey` now).

The dashboard's "Work inbox" tile and the inbox app card list the row by its own label ("Onboarding step waiting on you").

## 2. Rules (C3-D contract)

- **Who:** an HR role (or super-admin) whose active role holds `employees.update`, the same gate as S5 and D1. Every listed row is re-checked with `EmployeePolicy::update`. A manager or employee never sees one, nor does a non-HR role that happens to hold `employees.update`.
- **Scope:** the employee is **active** and in the user's active cities (`Employee::scopeInActiveCities`, so `activeCityIds() === null` means every city and `[]` means none). Completed journeys (`completed_at` set) and journeys where nothing waits any more never show.
- **Counts = list:** the scope is in SQL; `counts['journey']` is `count()` of the same query that lists (capped at `WorkInbox::PER_SOURCE`), and it is added to `counts['all']` (badge, bottom bar, dashboard).
- **Cost:** two queries per source (list + count), the employee, its city and `account_exists` eager-loaded. No per-row `steps()`: the digital-card validity check (card contract, position, program) is **not** read; the edit page shows it and refuses the hand-over until the card works.
- **Privacy:** name, city, step names, due date, Late. No pay, contract terms or national id.

## 3. Adding a renewal or exit source

The pieces: `App\Services\Inbox\JourneyInboxSource` (abstract: gate, scope, count, row shape), `OnboardingInboxSource` (the example), `JourneyInbox::SOURCES` (the registry WorkInbox reads).

1. **Class** `app/Services/Inbox/RenewalInboxSource.php` (or `ExitInboxSource.php`) extending `JourneyInboxSource`:
   - `name()`: `'renewal'` / `'exit'`. It is the row key prefix (`renewal:{id}:journey`) and the strings group.
   - `pending()`: the unfinished journeys with a step waiting on HR, as **one query on the journey table** that has an `employee` relation, e.g. `RenewalJourney::query()->whereNull('completed_at')->whereNull('decision')->with(['employee.city', 'contract'])->orderBy(...)`. Do **not** add the city or active filter: the base adds both.
   - `steps($journey)`: `[['key' => 'decide', 'state' => self::STATE_TODO]]`, from the loaded model only (no queries).
   - `due($journey)`: e.g. `$journey->contract?->end_date`; `late($journey)`: e.g. `$due?->lt(today)`.
   - Optional: `waitingSince()` (default `started_at`, then `created_at`); `visibleTo()` only to **narrow** the gate.
2. **Register** it in `JourneyInbox::SOURCES`.
3. **Strings** in `resources/lang/{en,ar}/journey_inbox.php`: `renewal.title` and `renewal.step.{key}` for each step key.
4. **Test**: copy the shape of `tests/Feature/JourneyInboxTest.php` (city HR sees it + Late; other city, non-HR and completed do not; count = list; flat queries).

Nothing else changes: the filter, the row partial (`livewire/inbox/partials/journey.blade.php`), the counts and the dashboard read every source the same way. The decision itself (renew / stop, exit steps) stays on the employee page with its own service and policy, never in the inbox.

## 4. Files

`app/Services/Inbox/{JourneyInboxSource,OnboardingInboxSource,JourneyInbox}.php`; `app/Services/WorkInbox.php` (merge, `counts['journey']`, `hasSources`); `app/Livewire/Inbox/Index.php` (filter); `resources/views/livewire/inbox/index.blade.php` + `partials/journey.blade.php`; `app/Support/Dashboard/WaitingOnYou.php`, `app/Livewire/Dashboard/AppCard.php`, `resources/views/livewire/dashboard/decisions-card.blade.php` (row label); `resources/lang/{en,ar}/journey_inbox.php`; `tests/Feature/JourneyInboxTest.php`.

## 5. Limits

- Late journeys are not added to the dashboard's "Past the deadline" tile (it counts started leave and unrenewed contracts); they show Late in the inbox.
- A journey whose digital card cannot work yet still lists "Hand over the printed ID card" (as Needs HR); the edit page says why it is blocked.
- Verification: `php artisan test --filter=JourneyInboxTest` on PHP 8.5, then the inbox, dashboard and onboarding suites. **Not run here.**
