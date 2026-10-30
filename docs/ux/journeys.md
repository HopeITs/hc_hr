# D4 · Linked hire, renewal and exit journeys

Status: **designed; first slice selected and built (D4-I); delivered, awaiting review** (QA engineer + technical lead). Not marked done.
Date: 2026-10-30. Task: D4 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-21). Owner role: HR product owner + UX designer + backend lead.
Consumers: **D4-I** ([d4-i-implementation.md](d4-i-implementation.md)), G-R.

This is a design document. The maps in §3 describe **what the code does today**, read from source at the revision below. They are not browser-verified. Anything marked *proposed* does not exist yet.

---

## 1. Starting state and inputs

| Item | Value |
|---|---|
| Revision read | `rms-main` @ `2798d70` (D4-I code commit on top of `afd0968`) |
| B5-D / B5 | [employee-lifecycle.md](employee-lifecycle.md), [b5-implementation.md](b5-implementation.md): draft (D) → ready, awaiting contract (C) → active (A) → inactive (I). Activation and the login account happen at the first real contract (`EmployeeActivation`). |
| C3-D | [approval-source-contract.md](approval-source-contract.md): the work inbox holds leave, checklist and profile-change items. Journeys are **not** an inbox source yet (§7). |
| G-C | [g-c-gate.md](g-c-gate.md): review pending; nothing in it blocks this design. |
| DEC-09 | [decisions.md](decisions.md): the first journey is onboarding a new volunteer, draft → contract → login account → ID card → orientation session. **DEC-09 detail** (2026-10-30) settles orientation, ID card and due date (§6). |

## 2. Words used in the maps

| Term | Meaning |
|---|---|
| **Owner** | Who acts at this step. *HR* = a user with `employees.update` (or `contracts.*`) whose active city covers the volunteer (`EmployeePolicy`, `ContractPolicy`). *System* = a scheduled command or a model observer. |
| **Automated** | Happens without a person deciding at that moment: a consequence of an earlier human decision, or a date passing. |
| **Human decision** | An employment, access or record decision. A workflow label or a reminder never makes it. |
| **Authoritative record** | The one table and column that says whether the step is done. Nothing else stores it. |
| Step states (journey panel) | **Done**; **Needs HR** (waits on HR now); **Waiting** (an earlier step blocks it); **Blocked** (cannot finish without a fix elsewhere). Shown as icon + words + fill, never colour alone. |

## 3. Journey maps (as the code works today)

### 3.1 Hire: a new volunteer

| # | Step | Owner | Automated / human | Authoritative record | Due-date basis | Prerequisite | Reused data | Exceptions |
|---|---|---|---|---|---|---|---|---|
| H1 | Save draft record | HR (city) | Human | `employees.record_status = draft` | none | draft minimum (B5-D §6) | — | Duplicate national ID refused with name + city only. Out-of-city governorate refused. |
| H2 | Reserve a position (optional) | HR (city) | Human | Draft-type contract row (claims no seat) | none | H1 | position | Skipped: nothing is created. |
| H3 | Complete the record (→ state C) | HR (city) | Human | `record_status = complete`, `is_active = false` | none | full rule set | H1 data | Cannot go back to draft. "Review" flag after 60 days waiting. |
| H4 | First real contract | HR with `contracts.create`, city | **Human** (employment decision) | `contracts` row, non-Draft type | contract `start_date` | H3; position capacity (`Position::canAssign`, `inCity`) | H2 reservation is upgraded in place | Draft employee refused by the server. Full position refused. |
| H5 | Activate the volunteer | System (`EmployeeActivation`) | Automated, follows H4 | `employees.is_active = true` | at H4 | H4 real and active | — | Idempotent; a retry or second contract changes nothing. |
| H6 | Login account + password-setup email | System (`EmployeeActivation`) | Automated, follows H4 | `users.employee_id` | at H4 | username set | username → `<username>@hcsyria.org` | Work email already used: no account, HR sees an error toast, journey shows **Blocked** with "create it from Access Management". |
| H7 | Digital ID card works | System (`DigitalIdManager`) | Automated | `employees.digital_id_enabled`, card code from city + program + id | at H4 | active; contract position has a program; city has a code | city, program codes | Missing city/program code: **Blocked**, with `missingLinkReason`. |
| H8 | Printed card handed over | HR (city) | Human (marks a fact) | `onboarding_journeys.card_handed_over_at/_by` | none | H7 works | — | Refused while the digital card is not valid. Undo with confirmation. |
| H9 | Orientation date set | HR (city) | Human | `onboarding_journeys.orientation_scheduled_for/_by` | — | journey started (H4) | — | Can be changed until held; each change logged from → to. |
| H10 | Orientation held | HR (city) | Human (marks a fact) | `onboarding_journeys.orientation_completed_at/_by` | **due 14 days after the first contract's start** | H9, and the date has arrived | — | Not before the session date. Overdue: red **Late** tag + **one** in-app reminder to city HR. Undo with confirmation. |
| — | Equipment checklist | HR assigns; assignee delivers | Human | `checklists.delivered_status` (`ChecklistWorkflow`) | none | — | city default deliverers | **Not linked** to the journey (deferred, §5). |
| — | Leave balances | System at contract save | Automated | `leave_balances` per contract (`LeaveBalanceService`) | contract dates | H4 | contract | Unchanged; not a journey step. |

Journey complete when H6, H8 and H10 are all done (`onboarding_journeys.completed_at`). An Undo reopens it.

### 3.2 Renewal

| # | Step | Owner | Automated / human | Authoritative record | Due-date basis | Exceptions |
|---|---|---|---|---|---|---|
| R1 | Expiry warnings to the direct manager | System (`contracts:send-expiration-warnings`, 08:00) | Automated reminder | notification ledger (one per contract, manager, day) | 30, 21, 14, 7 days before `end_date`; annual and service contracts with auto-renew on | No direct manager: no warning. |
| R2 | Decide: let it renew, or stop renewal | HR (`manageAutoRenew`: HR active role + `contracts.update` + city) | **Human** | `contracts.auto_renew` | before `end_date` | Trial and terminated contracts can never auto-renew (`ContractObserver::saving`). |
| R3 | Contract ends | System (`deactivate:contract`, 21:30) | Automated | `contracts.is_active = false`; seat freed (`exist_emp − 1`) | day after `end_date` | — |
| R4a | Auto-renewal | System (`ContractAutoRenewalService`, 21:35) | Automated, follows R2 | new `contracts` row; pay tiers copied | starts the day after `end_date`, ends 31 Dec of the run year | Skipped if a successor on the same position and start date exists (retry-safe). Runs in console, so no capacity check. |
| R4b | Manual renewal ("renew as is" / "renew with edit") | HR with `contracts.create`, city (`ContractPolicy::renew`) | **Human** | new `contracts` row | HR chooses | Only an active, non-terminated contract. Runs `EmployeeActivation`: creates a missing account, starts **no** journey (the employee is already active). |

**Leave balances on auto-renewal: verified and fixed (2026-10-30).** `ContractAutoRenewalService` did not call `LeaveBalanceService`, so every auto-renewed contract started with no leave balances. It now creates them the same way `ContractCreate` does (in a savepoint, so an unpriceable balance is reported without undoing the renewal). Contracts renewed before the fix need `leaves:backfill`. Details: [renewal-journey-implementation.md](renewal-journey-implementation.md) §3.

**Built as the renewal journey (DEC-09 increment 2, 2026-10-30):** one `renewal_journeys` row per expiring contract, started by `renewals:track-journeys` 30 days before `end_date` (not for trial, terminated or Draft contracts). Steps: R1 warning (read from the notification ledger), R2 decision (the one record the journey stores: renew or stop, by whom, when, with undo; "stop" switches auto-renew off through the same `ContractAutoRenewalService::setAutoRenew` path as the contracts list, under `manageAutoRenew`), new contract (R4a/R4b, read from `contracts`), leave balances (read from `leave_balances`). Owner HR of the city; decision due on `end_date`, Late within 7 days of it. Panel on the employee edit page. See [renewal-journey-implementation.md](renewal-journey-implementation.md).

### 3.3 Exit

| # | Step | Owner | Automated / human | Authoritative record | Exceptions |
|---|---|---|---|---|---|
| E1 | Stop auto-renewal | HR (`manageAutoRenew`) | **Human** | `contracts.auto_renew = false` | — |
| E2 | Equipment return requested | System (`ContractObserver::updated` → `ChecklistWorkflow::requestBelongingsReturn`) | Automated, follows E1 | `checklists.returned_status = pending`, receiver notified | Only delivered items with no return cycle. An item whose city has no default receiver is **skipped**; HR assigns it by hand from the checklist tab. |
| E3 | Item returned / cycle closed | Receiver; then HR | Human | `checklists.returned_status = received`; HR finalize deletes the row | Expected-state check + lock: a double submit matches nothing. |
| E4 | Early termination | HR with `contracts.update`, city (`ContractEdit`) | **Human** | `contracts.terminated_at`, `termination_reason_id` → `is_active = false`, auto-renew forced off | Clearing the date does not reactivate. **Seat:** the observer has no `is_active` branch and `deactivate:contract` only picks active contracts, so a terminated contract appears **not** to free its seat. From source reading; to verify (§7). |
| E5 | Contract ends | System (`deactivate:contract`) | Automated | as R3 | — |
| E6 | Employee deactivated | HR toggle (`employees.update`, city, with confirmation) | **Human** | `employees.is_active = false` | Not offered for drafts or volunteers awaiting a first contract. |
| E6′ | Weekly sweep | System (`deactivate:employee`) | Automated | `is_active = false` | Only active employees with **no contracts at all**. A volunteer whose last contract ended is **not** deactivated by it: E6 stays a human step. |
| E7 | Access revoked and card disabled | System (`EmployeeObserver` → `AccessRevoker`, `DigitalIdManager::disable`) | Automated, follows E6/E6′ | sessions deleted, remember token cleared, roles stood down; `digital_id_enabled = false` | Idempotent; logged under `security` / digital-ID log. |

## 4. Consequential and irreversible actions

| Action | Effect that cannot be undone, or reaches outside | Human authority | Server check | Recovery |
|---|---|---|---|---|
| Discard draft (H1) | Hard-deletes the person and their reservation | HR of the city, after a confirm | `employees.update` in scope; only a draft with no real contract and no account | Re-enter. Activity log keeps a redacted event. |
| First real contract (H4) | Activates; creates a login; **sends a password email**; turns the card on; claims a seat; starts the journey | HR with `contracts.create` in the city | `ContractPolicy`, `Position::inCity` + `canAssign`, draft refused | Edit or terminate the contract; disable the account in Access Management; disable the card. **The email cannot be recalled.** The journey row stays (history). |
| Account creation (H6) | A working login | Follows H4; nobody decides it separately | `EmployeeActivation`, inside the H4 transaction | Access Management. |
| Card handed over (H8) / Orientation held (H10) | Records a fact | HR of the city | `EmployeePolicy::update`; expected-state check | **Undo** with confirmation; both the mark and the undo stay in the `onboarding` log. |
| Stop auto-renewal (E1) | Starts return cycles and **notifies receivers** | HR (`manageAutoRenew`) | `ContractPolicy::manageAutoRenew` | Switch it back on; the return cycles already started are not cancelled; HR handles them on the checklist tab. Notifications cannot be recalled. |
| Auto-renewal (R4a) | A new contract with copied pay | Follows HR's R2 choice | Successor check under lock | Edit or terminate the new contract. |
| Terminate contract (E4) | Contract inactive for good; auto-renew off | HR with `contracts.update` | `ContractPolicy` | Contract list decisions; seat counter to verify (§3.3). |
| Deactivate employee (E6, E6′) | Login cut off now; card shows "not valid" | HR (toggle, confirm) or the weekly sweep | `employees.update` in scope | Toggle back. The card stays off until HR enables it (DEC-02); the login works again because sign-in re-activates a role. |

**Rule kept:** no journey state, label or reminder deactivates anyone, removes an account or changes a contract. The journey records two facts (card handed over, orientation held) and shows the others.

## 5. Designed, selected, deferred (AC-D4-03)

| Part | Status | Note |
|---|---|---|
| Hire map (§3.1) | **Designed** | From source. |
| Renewal map (§3.2) | **Designed** | Describes today, plus the renewal journey below. |
| Exit map (§3.3) | **Designed** | Describes today; nothing new. |
| Onboarding journey H4–H10 with one record per step, owner, due date, Late tag, one reminder | **Selected** (DEC-09, DEC-09 detail) → **built as D4-I** | [d4-i-implementation.md](d4-i-implementation.md) |
| Renewal journey as a linked checklist | ~~Deferred~~ **Selected (DEC-09 increment 2) → built, 2026-10-30, awaiting tests on PHP 8.5 and review** | [renewal-journey-implementation.md](renewal-journey-implementation.md). Also fixes the auto-renewal leave-balance gap (§3.2). |
| Exit journey as a linked checklist | **Deferred** | Next increment below. |
| Equipment checklist inside the onboarding journey | **Deferred** | It already has its own workflow (`ChecklistWorkflow`). |
| Journey items in the work inbox / dashboard tile; "Onboarding" employee-list view | **Deferred** | Open points in D4-I §8. |

**Next increment (proposed, not started): "exit journey".** One row per leaving volunteer, started by HR stopping auto-renewal (E1) or terminating (E4). Steps: equipment return (read from `checklists`), contract end (read from `contracts`), deactivation (human, E6), card and access (read, E7). Owner: HR of the city. It needs a product-owner decision on: who starts it, whether the weekly sweep should cover "last contract ended", and the termination seat question in §3.3. Renewal follows after, built on R1/R2.

## 6. First-slice contract (input to D4-I)

Decided 2026-10-30 (DEC-09 detail).

| Item | Contract |
|---|---|
| Scope | A new volunteer's onboarding, from the record to the orientation session. Existing staff and re-hired former staff get **no** journey. |
| Start | The first real contract that activates a complete, inactive volunteer (`EmployeeActivation`). One journey per employee. |
| Steps and records | record → `employees.record_status`; contract → first real `contracts` row; account → `users.employee_id`; card → digital: `DigitalIdManager`, printed: journey; orientation → journey. **Only the last two are stored by the journey.** |
| Owners | HR of the volunteer's city for record, contract, card, orientation. System for the account. |
| Orientation | HR sets a date, then marks it held. Not before the date. Due **14 days after the first contract's start date**. |
| ID card | Digital card active at the first contract. HR marks "printed card handed over"; refused while the digital card is not valid. |
| Overdue | Red **Late** tag on the step; **one** in-app reminder to city HR (HR-SYR when the city has none), never repeated. |
| Authority | Every action: `EmployeePolicy::update` (active role, `employees.update`, city scope), re-checked on the server. |
| Audit | Activity log `onboarding`: started, scheduled (from → to), completed, card handed over, reopened, with the actor. |
| Retry | Each write locks the journey row and only moves from the expected state; a repeat does nothing and says so. |
| Schema | One additive table; no change to existing rows. |

## 7. Open points

| # | Item | Owner |
|---|---|---|
| 1 | Early termination may not free the position seat (§3.3 E4). Source reading only; needs a test before it is called a defect. | Backend |
| 2 | **Resolved 2026-10-30 (product owner chose to fix it):** a recruitment hire (`RecruitmentHiringConversionService`) now starts the journey too, right after its contract. Their login account is still created by HR from the hire page, so the account step shows "no login account yet" until then. | Done |
| 3 | **Fixed 2026-10-30:** auto-renewed contracts now get their leave balances (§3.2). Older ones need `leaves:backfill`. | Done (backfill: Ops) |
| 4 | Journeys are not a work-inbox source (C3-D) and have no dashboard tile. | Next increment |
| 5 | Weekly sweep does not cover "last contract ended" (§3.3 E6′); deactivation stays manual. Keep, or change by decision. | Product owner |

## 8. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-D4-01** All three journeys have a step/state/owner map and concrete first-slice scope. | **Delivered, awaiting review** | §3.1–3.3, §6 |
| **AC-D4-02** Each irreversible or consequential action has a defined human authority and recovery consideration. | **Delivered, awaiting review** | §4 |
| **AC-D4-03** The document states which parts are designed, selected or deferred. | **Delivered, awaiting review** | §5 |

Review (QA engineer + technical lead; product owner for §5 next increment and §7 #2, #5): **pending.**
