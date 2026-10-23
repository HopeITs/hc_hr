# B5-D · Employee draft lifecycle and minimal onboarding (proposed)

Status: **decided, awaiting review.** HR's answers to B5D-Q1…Q10 were recorded on 2026-10-20 (§8). Not marked done until the technical lead has reviewed this document (and A1/P0 are signed).
Decision sheet (EN/AR): [b5-d-decision-sheet.md](b5-d-decision-sheet.md).
Date: 2026-10-20. Task: B5-D in [ux-prompts-v3.md](ux-prompts-v3.md) (R-17). Owner role: HR product owner + backend lead. Reviewer: HR product owner + technical lead.
Consumers: **B5** (implementation), D1-D, D4.

This is a decision document. **No application code, schema or data was changed.** Every column, state or screen name below is **PROPOSED** until B5 implements it.

---

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision inspected | `hr-ux-by-rafi` @ `c4ab8dc2`, level with `origin/main` (includes Rita's local-PDF and trial-contract commits; none touches employee creation) |
| P0 / A1 | Delivered with AC evidence; review sign-off pending ([start-state.md](start-state.md), [test-baseline.md](test-baseline.md)). B5-D cannot be signed before them. |
| Decisions | [decisions.md](decisions.md): **DEC-04** (employee draft) and **DEC-09** (first journey = onboarding a new volunteer) settled 2026-10-16. Quoted in §4. |
| Related | [access-policy.md](access-policy.md) (A2-D): who may see a record. Drafts follow it (§5.4). |

## 2. How a new volunteer is created today (task step 1)

### 2.1 The path

1. HR opens *Employees → Add New* (`EmployeeCreate`). Only the **Information** and **Contact** tabs work; **every other tab is disabled** until the first save (`:disabled="!isset($infoForm->id)"` in `employee/form.blade.php`).
2. **Save** (`PersonalInfoForm::saveInformation`) validates the full required set (§2.2) and calls `Employee::create`. If a validation error is on the other tab, the form jumps to it.
3. After the save, an optional **"assign position (draft)"** prompt appears. Choosing a position creates a **Draft-type contract** (`DraftContractForm::createDraft`). *Skip* creates nothing. Both redirect to the edit page.
4. Later, the contract composer finds the Draft (`ContractCreate::draftContractFor`) and **upgrades** that same row to a real contract.

### 2.2 What the first save requires (source + database)

| Field | Required by the form | Database (`2025_06_20_115710_basic_tables`) | Note |
|---|---|---|---|
| First name AR + EN, last name AR + EN | yes | `first_name_*` NOT NULL | Four name fields |
| Father's name AR + EN, mother's name AR + EN | yes | nullable | Four more |
| Gender, marital status | yes | nullable | |
| Governorate (`city_id`) | yes, must exist, must be in the HR officer's active city | nullable | |
| **National ID** | yes; **unique**; **11 digits if nationality is Syrian**, otherwise ≤ 255 characters | unique, nullable | The duplicate guard DEC-04 relies on already exists |
| Nationality | validated as an integer | nullable | Decides the national-ID format |
| **Username** | yes, unique | **NOT NULL, unique** | Drives the profile URL, the **work email** (`<username>@hcsyria.org`) and today's **public ID-card URL** |
| Work email | required **if "create account" is ticked** | users.email unique | Built from the username |
| Photo, phones, address, DOB, joining date, social links, board fields | optional | nullable | Phones must be `09` + 8 digits |

### 2.3 What happens on that first save

| Effect | Today | Evidence |
|---|---|---|
| **Active flag** | `is_active` is **true** at once (form default `true`, column default `true`) | `PersonalInfoForm::$is_active`, migration |
| **Login account** | "Create account" is **ticked by default**, so a `users` row with role `employee` is created **and a password-reset email is sent** in the same request | `PersonalInfoForm::$create_account = true`, `persistAccount()` → `Password::sendResetLink` |
| **Digital-ID token** | Generated on creation | `Employee::booted()` |
| Counts | Counted as **active staff** in dashboard and statistics cards, the employee table, birthdays and news, leave notifications, the directory and org chart, and badges | `is_active` consumers: `Dashboard\Index`, `JobLevelEmployeeStatistics`, `EmployeeStatistics`, `EmployeeTable`, `News\TodayEventsCard`, `NewsForm`, `LeaveNotificationService`, `BadgesTable`, `User` |

### 2.4 What happens afterwards, automatically

| Job / hook | Behaviour | Effect on a just-created person |
|---|---|---|
| `deactivate:employee` (**weekly**, `routes/console.php`) | `is_active = 0` for every active employee with **no contracts at all** | A person saved **without** a Draft reservation becomes **inactive** within a week. |
| `EmployeeObserver::updated` | When `is_active` turns false: **disable the digital ID** and **revoke the login** (`AccessRevoker`: sessions deleted, roles deactivated) | That new person's account is revoked and their card disabled, although they never worked. |
| `deactivate:contract` (daily) | Ends contracts whose `end_date` passed | A Draft has no dates, so it is never touched. |
| `ContractObserver` | A **Draft** claims **no seat**; the seat is claimed when it is upgraded | A Draft can be made on a full position (tested). |

**Consequence:** today "incomplete", "never started" and "former employee" all end up as `is_active = false`. Someone with only a Draft reservation stays `is_active = true` indefinitely. That is the confusion B5-D has to remove.

### 2.5 Permissions involved

`employees.create` / `employees.update` (HR roles, general-manager), checked server-side with city scope (`EmployeePolicy::create/update`). `config/access-control.php` classifies `saveInformation`, `createDraftAndContinue` and the draft-cascade helpers. **Employees are not soft-deleted:** deleting one removes the row permanently.

## 3. Three different "drafts" (AC-B5-D-01)

| | **Employee draft** (proposed, new) | **Inactive employee** (exists) | **Draft contract** (exists) |
|---|---|---|---|
| Meaning | An incomplete person record, being entered by HR; **never worked yet** | Someone who **worked and left**, or was deactivated | A **position reservation** for a person, not yet a real contract |
| Stored as | Proposed `employees.record_status = 'draft'` with `is_active = false` | `is_active = false`, `record_status = 'complete'` | `contracts.type = 4` (`ContractType::Draft`), no dates, no pay, no seat |
| Shown as | **"Incomplete"** in a separate HR list | "Inactive" | "Draft" on the contract card |
| Counted as staff | No | No | No (no seat) |
| Login account | **None** (DEC-04) | Revoked when they left | — |
| Digital-ID card | **None** issued | Disabled ("not valid", A2-D) | — |
| Leave balances | None | Historical only | None until upgraded |
| Weekly deactivation job | **Must skip it** (§5.5) | Already inactive | — |
| Ends by | **Completion** (explicit Save) or **discard** | Re-activation by HR | **Upgrade** in the composer, or deletion |

Rule for B5: **never use `is_active` alone to mean "draft"**. Every screen that shows "inactive" must exclude drafts, and every screen that counts staff must keep excluding them.

## 4. What HR already decided

**DEC-04 (2026-10-16):**

| Point | Decision |
|---|---|
| Draft state | Yes. An incomplete volunteer is saved as a draft, listed separately as "incomplete", and not counted as active. |
| Minimum to save a draft | Name + governorate + national ID (the ID prevents duplicates). |
| Login account | Created when the first contract is activated; drafts and volunteers without a contract get none. |
| Saving | Explicit Save button **plus** an automatic draft so nothing is lost if the page closes; the volunteer becomes active only on Save. |

**DEC-09:** the first lifecycle journey is onboarding a new volunteer: draft → contract → login account → ID card → orientation session.

## 5. Proposed lifecycle (task steps 2–3)

### 5.1 States

| State | `record_status` (proposed) | `is_active` | Account | Card | Contracts |
|---|---|---|---|---|---|
| **D: Draft / incomplete** | `draft` | false | none | none | none, or one Draft reservation (Q6) |
| **C: Complete, awaiting first contract** ("Ready, awaiting contract") | `complete` | **false** (Q4) | none | none | none, or a Draft reservation |
| **A: Active, contracted** | `complete` | true | created at first contract activation (DEC-04) | issued (DEC-09) | ≥ 1 real contract |
| **I: Inactive** (former) | `complete` | false | revoked | disabled | ended |

### 5.2 Transitions

| From → To | Trigger | Who (server-checked) | Preconditions | Side effects |
|---|---|---|---|---|
| (none) → **D** | "Save draft" (autosave later, §5.6) | HR with `employees.create` in the governorate's city | Draft fields valid (§6: 8 names, governorate, national ID, username), national ID and username unique | Row created with `record_status = draft`, `is_active = false`; **no account, no reset email, no card token issued** (token generation moves to completion, B5D-Q8) |
| D → D | Further saves | HR with `employees.update` in city scope | Minimum still valid | "Saved at hh:mm" shown |
| D → **C** | **Explicit Save** of the complete record | same | Full required set (§2.2, as decided in §6) valid | `record_status = complete`; `is_active` per B5D-Q4; still no account |
| C → **A** | First **real** contract becomes active (composer, or a Draft upgraded) | HR with `contracts.create` | Contract valid; position capacity | `is_active = true`; **account created** (one per person, idempotent); reset email; digital-ID token issued (A2-D option C) |
| A → **I** | Deactivation (manual, or contracts ended) | HR / existing jobs | — | Existing behaviour: card disabled, access revoked |
| I → A | Re-activation | HR | New contract | Existing behaviour, not changed here. Restoring a revoked account's roles is an Access Management action; this document does not define it. |
| **D → discarded** | "Discard draft" | HR for the draft's city, after a confirmation (Q7); drafts untouched for 90 days are listed for review, never auto-deleted | No contract other than a Draft reservation; no account | Draft reservation deleted with it; activity log keeps the event, not the national ID |
| C/A → D | **Not allowed** | — | — | A complete record never goes back to draft |

### 5.3 What a draft must never trigger

No account, no password email, no digital-ID token or card, no leave balances, no notifications to managers, no badge eligibility, no appearance in the directory, org chart, dashboard team card, birthdays or statistics. It is never picked up by the weekly deactivation job, so **no `AccessRevoker` run and no card disable for a draft**.

### 5.4 Ownership and visibility

| Question | Proposal |
|---|---|
| Who sees drafts | HR for the draft's governorate (city scope), HR-SYR, super-admin. **Not** the directory tier of A2-D: a draft is not a colleague yet. |
| Where | A separate **"Incomplete"** list or filter on the Employees page, with "last saved at" and "saved by" columns. |
| Who may resume | **Any** HR officer in scope, not only the creator, so that work is not stuck when someone is away. |
| Who may complete (D → C) | Same. |
| Duplicate national ID | Rejected at draft time. **Decided (Q9):** the message shows the existing record's **name and city**, also when it is in another city. Name and city are Directory-tier fields every staff member may see across governorates (DEC-01). **Note for the security reviewer:** when the existing record is a **former** employee or another city's **incomplete** record, this message reveals a person that A2-D would otherwise not show to that officer. B5 shows only name and city: nothing else, and no link to the record. |

### 5.5 Required change to the weekly job (for B5)

**Decided (Q5):** `deactivate:employee` **always skips drafts**, and **never auto-deactivates** state **C**. Volunteers in state C for more than **60 days** are listed for HR review instead. Because drafts and state C keep `is_active = false` (Q4), the job's current selection (`is_active = 1` and no contracts) already leaves them alone; the new part is the 60-day review list. Without this, a complete volunteer waiting for their contract would be deactivated and revoked within a week, as today (§2.4).

### 5.6 Saving: explicit save first, autosave second (task step 4)

DEC-04 asks for **both** an explicit Save and an automatic draft. **Decided order (B5D-Q10, 2026-10-20):**

1. **Phase 1 (B5), decided (Q10):** an explicit **"Save draft"** button, enabled as soon as the draft fields (§6) are valid, plus a visible **"Saved at hh:mm by …"** timestamp, plus the B2 dirty-leave guard so that closing the page with unsaved changes warns first. The explicit **"Save"** completes the record (D → C).
2. **Phase 2 (after Phase 1 is used):** **server-side** autosave of the draft, debounced on field or section change, **only after** the minimum is valid. It always updates the same draft row (no duplicates) and shows the same timestamp.

**Not allowed:** saving the form to the browser (`localStorage`/`sessionStorage`). It holds national IDs and personal data (SHARED RULES: no sensitive browser storage). Before the minimum is valid, nothing can be saved, and the dirty-leave guard is the only protection; the form must say so ("enter the names, governorate, national ID and username to save a draft").

## 6. Minimum-field matrix (task step 2)

Decided 2026-10-20 (B5D-Q1…Q3):

| Field | Draft | Complete | Schema impact |
|---|---|---|---|
| Name | **required: all 8 name fields** (first, last, father, mother; AR + EN), as today (Q1) | same | none |
| Governorate (`city_id`) | **required**, in the officer's scope | required | none |
| National ID | **required**, unique; 11 digits only when nationality is Syrian | required | none |
| Nationality | **optional**, as today (Q3). Consequence: with nationality empty, any national-ID format is accepted, so a mistyped Syrian ID is not caught and duplicate detection is weaker. | as today | none |
| Username | **required, unique, as today** (Q2). It still forms the work email, but **is not used in the digital-ID card address** (see [access-policy.md](access-policy.md) §5: the card moves to a secure token link). | required, unique | none |
| Gender, marital status | optional | required | none (already nullable) |
| Account (work email) | **never** | created at first contract activation, not at Save | "Create account" checkbox removed from the create flow |
| Photo, phones, address, DOB, joining date, social, board | optional | optional (as today) | none |
| Position reservation (Draft contract) | **allowed** (Q6); released if the draft is deleted | optional | none |

## 7. Existing staff and accounts after rollout (AC-B5-D-02)

| Existing data | Treatment |
|---|---|
| Every existing employee row | Backfilled `record_status = complete`. **No change** to `is_active`, names, IDs or usernames. |
| Existing accounts | **Untouched**: no recreation, no reset emails, no role change. |
| Complete employees **without** an account (made with the box unticked) | **No automatic account creation** at rollout (it would email people en masse). HR creates one deliberately, or it happens at their next contract activation. **B5D-Q8.** |
| Existing Draft-type contracts | Untouched; the composer upgrade path is unchanged. |
| Existing inactive employees | Stay inactive; still listed as "Inactive", never as "Incomplete". |
| Digital-ID tokens of existing staff | Untouched (A2-D governs them). |
| Weekly job | Same behaviour for existing complete staff, plus the draft skip and the state-C rule (§5.5). |

Migrations (for B5, not here): additive `record_status` with default `complete` for existing rows; username nullability if Q2 so decides. Rollback: the columns can be ignored by old code. A draft created after rollout must never be shown as "active" by old code, so a rollback must also treat `record_status = draft` rows as inactive, which `is_active = false` already ensures.

## 8. HR decisions (AC-B5-D-03)

Recorded 2026-10-20 as **HR's final answers** (given by Rafi for HR). Q2, Q5 and Q8 involve the backend lead; no separate backend-lead confirmation is recorded.

| ID | Question | Decision | Current logic it changes | Was it the recommendation? |
|---|---|---|---|---|
| **B5D-Q1** | Name fields required for a draft | **All 8 name fields** (first, last, father, mother; AR + EN) | none: same as today's first save | No (recommended 4) |
| **B5D-Q2** | Username in a draft | **Required, as today; not used in the digital-ID card address** | none in employee creation; the card address follows A2-D (token link) | No (recommended optional) |
| **B5D-Q3** | Nationality in a draft | **Optional, as today** | none. Weaker national-ID check when empty (§6). | No (recommended required) |
| **B5D-Q4** | When a volunteer counts as active | **From the first real contract.** Before that, state C, "Ready, awaiting contract", not counted. | Today: active at the first save | Yes |
| **B5D-Q5** | Weekly deactivation job | **Skip drafts always; completed volunteers awaiting a contract are left alone for 60 days, then listed for HR review; no automatic deactivation** | Today: deactivates (and revokes) any active person with no contract, weekly | Yes |
| **B5D-Q6** | Position reservation from a draft | **Allowed; the reservation is released (deleted) if the draft is deleted** | Today: reservation allowed after the first save; no draft concept | Yes |
| **B5D-Q7** | Deleting incomplete records | **HR for that city may delete after a confirmation; records untouched for 90 days are listed for HR review; never deleted automatically** | Today: no drafts; employee deletion is permanent (no soft delete) | Yes |
| **B5D-Q8** | Accounts for existing staff without one | **No automatic creation at go-live.** HR creates them deliberately, or at the person's next contract. New volunteers: account at first contract (DEC-04). | Today: account at first save, "create account" ticked by default | Yes |
| **B5D-Q9** | Duplicate national ID in another city | **Show the existing record's name and city** | Today: generic "already taken" message | No (recommended "exists, ask national HR") |
| **B5D-Q10** | Saving | **"Save draft" + "Saved at hh:mm" + leave warning first; server-side autosave in a later step; never browser storage** | Today: one Save needing every required field | Yes |

**Consequences for B5:**
- Q1 + Q2 + Q3: the draft needs the same identity fields as today's first save except gender and marital status. **No schema change** for names or username.
- Q4 + Q5: drafts and state-C volunteers keep `is_active = false`, so the weekly job (which selects `is_active = 1`) already leaves them alone. The 60-day review list is new.
- Q9: name and city only; see the note in §5.4.

## 9. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-B5-D-01** The state/transition table distinguishes employee draft, inactive employee and draft contract. | **Met** | §3 (three-way table), §5.1 states, §5.2 transitions, §5.3 forbidden side effects |
| **AC-B5-D-02** Existing staff and accounts retain defined behaviour after rollout. | **Met** | §7 historical-data matrix; §5.5 weekly-job rule; Q8 (no mass account creation) |
| **AC-B5-D-03** HR decides required fields, activation authority and save/resume behaviour before B5. | **Met (decided 2026-10-20)** | §8: required fields (Q1–Q3), activation (Q4), weekly job (Q5), reservation (Q6), discard authority (Q7), accounts (Q8), duplicates (Q9), save/resume (Q10). Who may resume and complete: §5.4. |

Verification performed: source and schema trace at `c4ab8dc2` (files cited per row), the weekly schedule in `routes/console.php`, and the observers. No application test is claimed from this review. **Formal review (technical lead): pending.** B5-D is **not** marked done until it is reviewed.

## 10. Handoff

| Consumer | Takes from this document |
|---|---|
| **B5** | §3 states, §5 transitions and forbidden side effects, §5.5 job change, §5.6 saving order, §6 field matrix, §7 rollout. Build only the parts whose §8 decisions are recorded. Replace the disabled-tab stack with sections that become available once a draft exists. Tests: draft creates no account/email/token; draft not counted anywhere in §2.3; weekly job skips drafts; duplicate national ID across cities; completion → active rules; existing staff unchanged. |
| **D1-D** | The profile shows a draft's state and "saved at"; drafts are not directory entries (A2-D). |
| **D4** | DEC-09's onboarding journey maps to D → C → A: contract activation creates the account and the card; the orientation session follows. The journey's first step is this document's draft. |
| **A2 / A2-D** | Drafts are outside the Directory tier; the digital-ID token is issued at C → A, not at creation. |
