# X2-I · Anonymous task-attempt telemetry: implementation notes

Status: **implemented and verified, collection OFF by default, awaiting review** (QA engineer + technical lead). Not marked done until reviewed.
Date: 2026-10-22. Task: X2-I in [ux-prompts-v3.md](ux-prompts-v3.md) (R-15). Consumers: X2-R, G-R (final handoff).

## 1. Starting state and decision

| Item | Value |
|---|---|
| Revision | `hr-ux-by-rafi` @ `8fb985f0` (= `main` with A3, A4, plus B0 and A7) |
| Prerequisites | X2 (instruments, [usability-baseline.md](usability-baseline.md)); A2 (implemented on `main`) |
| **DEC-10** (2026-10-16, [decisions.md](decisions.md)) | "Yes, anonymous: no names or personal data, stored on our own server only, deleted after 90 days." Because DEC-10 selected telemetry, the plan makes X2-I required before X2-R and G-R. |

**AC-X2-I-01 (policy selected or deferred):** selected by DEC-10. The details DEC-10 did not settle are named in §7.

## 2. What is recorded (task-plan step 2)

Only the four X2 tasks ([x2/task-scripts.md](x2/task-scripts.md)):

| Task | Starts when | Completes when | Cancelled when |
|---|---|---|---|
| `leave_request` (A) | *My Leaves* new-request form opens (`MyLeaveRequestModule::create`) | a new request is saved (`processSave`, after `store()`) | the form is opened again while an attempt is open |
| `employee_lookup` (B) | the first search on the employee list (global or Name column; `EmployeeTable::updatedGlobalSearch` / `updatedSearch`) | a profile is opened (`EmployeeProfile::mount` with an employee) | — |
| `leave_review` (C) | a leave decision opens (`AllLeaveRequestModule::openDecision`) | a decision is submitted (`submitDecision`) | the decision is closed (`closeDecision`), or another one is opened |
| `contract_create` (D) | the contract composer opens for a **new** contract (`ContractCreate::mount`, not renewals) | the contract is created (`submit`) | — |

**Abandonment:** an attempt with no outcome after **30 minutes** counts as abandoned **in the report**; nothing is written when the window passes. Younger open attempts are "in progress" and left out of every denominator.

**Validation errors:** each attempt carries one yes/no flag, `had_validation_error`, set once when the component shows validation errors while the attempt is open. It is a flag, never a count, so a failure seen twice is not counted twice.

**Duplicates:** an attempt is a random UUID. `finish()` updates only rows whose outcome is still empty, in one conditional statement, so a double click, a retried request or a late close can never record a second outcome. Reopening a task closes the earlier open attempt as cancelled instead of leaving two open.

**Not an attempt model by user (task-plan "data integrity"):** there is no user ID in the table at all. Task B's attempt ID lives in the user's **session** between the search and the profile, never in the table.

## 3. What is stored (task-plan step 3, AC-X2-I-03)

Table `task_attempts` (PROPOSED schema, migration `2026_10_22_000001_create_task_attempts_table.php`):

| Column | Content |
|---|---|
| `id`, `attempt_uuid` | row number; random UUID |
| `task` | one of the four task names |
| `role_context` | the active role, **coarsened**: `hr` (any `HR-*`, so no city), `manager`, `general-manager`, `employee`, `admin` (super-admin), `none` (signed in, no role), `guest`, `other` |
| `locale` | `en` or `ar` |
| `started_at`, `finished_at`, `duration_ms` | times |
| `outcome` | `completed`, `cancelled`, or empty |
| `had_validation_error` | yes/no |

**Never stored:** user, employee, city, record ID, names, form values, leave reasons, government IDs, pay, documents. A test fails if a column is added (`test_the_table_has_no_column_that_could_identify_a_person_or_record`). `TaskTelemetry` never receives any of those values, so it cannot pass them on.

**Residual risk, stated plainly:** role + language + time could point at one person in a tiny office (e.g. the only Arabic-interface HR officer active at a given minute). Mitigations: no city, CLI-only aggregate report, groups under 5 hidden, 90-day deletion.

## 4. Retention, access, switch

| Rule | Implementation |
|---|---|
| **Off by default** | `config/telemetry.php` `enabled` = `env('TASK_TELEMETRY_ENABLED', false)`. With it off, nothing is written: the migration alone starts no collection on any host. `.env.example` documents the switch. |
| **Turning it on in production** | A separate, authorized decision (§7 X2I-Q3), not part of this change. |
| **90-day deletion (DEC-10)** | `php artisan telemetry:prune`, scheduled daily at 03:10 in `routes/console.php`. Runs whether or not collection is on. |
| **Own server only (DEC-10)** | Written to the application database; no third-party analytics, no browser storage, no external call. |
| **Who can read it** | `php artisan telemetry:report [--days=30]`, on the server's command line only: there is **no web page and no permission** that exposes it (§7 X2I-Q1). |
| **Telemetry never breaks a task** | A failed telemetry write is reported to the log and swallowed. |

## 5. The report (aggregates with denominators)

Per task: an "all" row plus one row per role and language. Columns: Attempts, Completed, Cancelled, Abandoned, Completion (`x/n (%)`), With validation error (`x/n (%)`), Median time of completed attempts. **Attempts = Completed + Cancelled + Abandoned** (tested). Groups with fewer than **5** attempts show `<5` instead of numbers.

**What it cannot see**, so moderated research (X2/X2-R) stays necessary: help given (H-levels), whether a "completed" task produced the **right** result (X2's F outcome), comprehension, and SUS/SEQ. A completed leave request with the wrong dates is still "completed" here.

## 6. Verification

**Tests** (synthetic, SQLite `:memory:`):

| Test | Proves |
|---|---|
| `TaskTelemetryTest` (14) | anonymous schema; off by default and when disabled; first outcome wins; unknown task rejected; role coarsening (HR without city); **synthetic start/success/failure/retry/abandon/in-progress series reconciles** (4 = 2 + 1 + 1, rates and median); small groups hidden; 90-day prune; report command runs; task B search → profile is one completed lookup; a stale lookup is not continued; task D starts on the composer; **the browser cannot call the trait's lifecycle hook** (`DirectlyCallingLifecycleHooksNotAllowedException`) |
| `LeaveRequestSubmitFlowTest` (+2) | task A: error then fixed save = one completed attempt, flagged; a double-clicked save does not add an outcome; reopening cancels the earlier attempt |
| `LeaveRequestWorkflowTest` (+1) | task C: closed decision = cancelled; reopened, error then fixed rejection = completed and flagged |
| `LivewireMutationCoverageTest` | passes: the five new public lifecycle hooks are classified in `livewire_exempt` with a comment (`dehydrateTracksTaskAttempt` ×3, `EmployeeTable::updatedGlobalSearch` / `updatedSearch`); Livewire blocks browser calls to `dehydrate*` and `updated*` |

**Tests catch real breakage:** removing the decision's `finishTaskAttempt()` and the validation-error flag made the task A and C tests fail (2 failures); both restored.

**Full suite:** 714 tests, 709 passed, 5 failed: the five known pre-existing failures (A1 register #6, #11, #12, #13 and the remote-Chrome PDF test, for A6). No new failure.

**Browser, Arabic, telemetry on** (separate synthetic copy `C:/laragon/tmp/hc-hr-x2i`, launch entry "hc-hr X2-I (synthetic SQLite, telemetry on)", port 8395):

| Run | Stored row |
|---|---|
| Staff, `/ar/leaves`: open form, save with an error, fix, send | `leave_request`, `employee`, `ar`, 9.3 s, `completed`, `had_validation_error` = 1 |
| HR, `/ar/employees`: search «نجار», open a profile | `employee_lookup`, `hr`, `ar`, 2.3 s, `completed` |
| `telemetry:report --days=1` (min cell set to 1 for this display only) | rows reconcile; empty tasks show "—" |

No other column was filled; no name, reason or record ID appears anywhere.

## 7. Open details for the product owner (DEC-10 did not settle them)

| ID | Question | Implemented default | Change needs |
|---|---|---|---|
| **X2I-Q1** | Who may read the report? | Server command line only (people with server access); no web page | A permission and a page, if a wider audience is wanted |
| **X2I-Q2** | When does an unfinished attempt count as abandoned? | 30 minutes | `telemetry.abandon_after_minutes` |
| **X2I-Q3** | When is collection switched on in production, and for how long? | Off | `TASK_TELEMETRY_ENABLED=true` on the server: a separate authorized action, ideally announced to staff |
| **X2I-Q4** | Smallest group shown in the report | 5 | `telemetry.min_cell` |

## 8. Hand-offs and limitations

- **A5-M (Rita):** the leave form's *Cancel* is still a link that reloads the page, so a cancelled leave request currently ends as **abandoned** after 30 minutes, not "cancelled". When A5-M adds the local close path, call `$this->finishTaskAttempt('cancelled')` there (one line in `MyLeaveRequestModule`).
- **Merge note:** `MyLeaveRequestModule`, `AllLeaveRequestModule` and `ContractCreate` each gained 1–3 lines plus `use TracksTaskAttempt`. A5-M/A6 changes in the same files should merge cleanly, but check those lines after merging.
- **X2-R:** compare telemetry with moderated results only for the same task definitions (scripts v1.1); report denominators and the "cannot see" list in §5.
- **Migration:** additive and reversible (`down()` drops the table). Run on production only together with the deploy; it starts no collection.

## 9. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-X2-I-01** Optional measurement policy is selected or the task is explicitly deferred. | **Met** | DEC-10 selected it (§1); defaults for the unsettled details in §7 |
| **AC-X2-I-02** Completion/error/abandonment measures reconcile to defined attempts without double counting. | **Met** | §2 definitions; §6 synthetic series 4 = 2 + 1 + 1; first-outcome-wins and double-click tests; error flag is boolean |
| **AC-X2-I-03** Only approved minimized data is stored, and authorized reports honor retention/access rules. | **Met** | §3 schema + guard test; §4 off by default, 90-day prune, CLI-only report, small groups hidden; §6 browser rows |

**Formal review (QA engineer + technical lead): pending.** X2-I is **not** marked done.
