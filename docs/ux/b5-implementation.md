# B5 · Resumable employee onboarding: implementation notes

Status: **implemented and verified on synthetic data, awaiting review** (QA engineer + technical lead). The live migration is **not run**; it needs separate authorization (§7). Not marked done until reviewed.
Date: 2026-10-23. Task: B5 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-17). Consumers: M1, G-B, D1, D4-I.

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision | `hr-ux-by-rafi` @ `b4ffc8f1` (= `main` with B1–B4, B6, M1, plus B2) |
| DEC-04 | Settled 2026-10-16 ([decisions.md](decisions.md)): draft state, minimum, account at first contract, explicit save plus autosave |
| B5-D | [employee-lifecycle.md](employee-lifecycle.md): states, transitions, field matrix, rollout; HR answers B5D-Q1…Q10 recorded 2026-10-20 |
| B2 | Unsaved-changes guard, error-summary links, duplicate protection ([b2-implementation.md](b2-implementation.md)) |
| A2 | Access policy and city scope (`EmployeePolicy`, `activeCityIds()`) |

## 2. What was built (task steps 1–4)

### 2.1 Lifecycle and schema (step 1)

Additive migration `2026_10_23_000001_add_record_status_to_employees_table`:

- `employees.record_status` (`draft` | `complete`, default `complete`, indexed);
- `employees.last_saved_by` (nullable FK to users, null on delete).

Every existing row becomes `complete` through the default; nothing else changes.

| B5-D state | Stored as | How it is reached |
|---|---|---|
| **D** Draft | `record_status = draft`, `is_active = false` | "Save draft" on the create page |
| **C** Ready, awaiting contract | `complete`, `is_active = false`, no real (non-Draft-type) contract | "Save" on create, or "Complete record" on a draft |
| **A** Active | `complete`, `is_active = true` | First real contract (`EmployeeActivation`) |
| **I** Inactive | `complete`, `is_active = false`, has had a real contract | Existing paths, unchanged |

**`is_active` is never used alone to mean "draft"** (B5-D §3). `Employee` gains:
- `isDraft()`, `isAwaitingContract()`, `realContracts()`;
- scopes `drafts()`, `completeRecords()`, `awaitingContract()`, `contractable()`.

`record_status` is not fillable, and the form never sends `is_active`: both are set only by server code.

### 2.2 Saving and resuming (steps 2–3, AC-B5-01)

| Action | Validation | Result |
|---|---|---|
| **Save draft** (create, or edit of a draft) | B5-D §6: the 8 name fields, governorate (in scope), national ID (unique, 11 digits if Syrian), username (unique). Gender, marital status and nationality optional; anything filled is still checked. | Draft row; `last_saved_by` set. No account, no reset email, no legacy card token. |
| **Save** (create) / **Complete record** (draft) | The full rule set, as today | State C. Still no account (B5D-Q4, Q8). |
| **Save** (complete record) | as today | unchanged |
| Save draft on a complete record | — | 403: a record never goes back to draft |

- **Resume:** any HR officer whose city scope covers the record can open a draft (`EmployeeEdit` → `Gate::authorize('update')`). HR of another city gets 403.
- **Progressive sections instead of unexplained disabled tabs:** an onboarding panel (`partials/onboarding-status.blade.php`) shows:
  - a live checklist of what a draft needs and what completing needs. Each item opens its tab and focuses the field, using the B2 reveal helper (`window.hcRevealField`);
  - why the other sections are not open yet ("open once the record is saved, as a draft or complete");
  - for a draft, "Saved {time} by {name}" and **Discard draft**;
  - for state C, "Ready, awaiting contract" with **Create contract**.

  Tabs other than Profile and Contact still unlock after the first save. Those tabs hold rows that belong to a saved employee.
- **Duplicate records:** `EmployeeCreate::$submissionToken` (`#[Locked]`), as B2 did for contracts. A save resent after a lost response continues on the record the first one created instead of creating a second person.
- **Duplicate national ID (B5D-Q9):** refused with "This national ID is already registered for {name} ({city})", also across cities. Name and city only, no link (B5-D §5.4 security note).
- **Unsaved changes:** the B2 guard applies. Before the draft minimum is filled in, nothing can be saved, and the panel says so; nothing goes to browser storage (B5D-Q10).

### 2.3 Nothing activates by accident (step 3, AC-B5-02)

| Path | Guard |
|---|---|
| Contract composer | Offers `contractable()` employees: active staff, plus complete volunteers awaiting their first contract. **Never drafts.** The server refuses a draft's `employee_id` ("This person's record is incomplete…"). |
| Status toggle (employee list) | 403 for a draft **and for a volunteer awaiting their first contract** (HR decision 2026-10-23, §6); the menu item is hidden for both. Former staff (who had a real contract) keep the toggle. |
| The form | `is_active`, `record_status`, `employee` and `id` are excluded from the saved payload. `create_account` is forced off unless the record is complete **and** active or already has an account (`mayHaveAccount()`). |
| Weekly `deactivate:employee` | Unchanged query (`is_active = 1`, no current contract), so drafts and state C are never selected and never run `AccessRevoker` (B5D-Q5) |
| Digital ID | The card resolves only for active employees (A2, `DigitalIdManager::resolve`). The legacy `digital_id_token` is not generated for drafts and is set at activation. |

### 2.4 Activation at the first contract (DEC-04, DEC-09)

New `App\Services\EmployeeActivation::activateForContract()`, called by `ContractCreate` after a real contract is saved (create, renewal, or a Draft-type reservation upgraded in place):

- inside a transaction with `lockForUpdate` on the employee: a complete inactive volunteer becomes active, and an employee without an account gets one (`<username>@hcsyria.org`, role `employee`);
- the password-setup email is sent **after** the commit;
- idempotent: a retry or second contract changes nothing;
- a Draft-type reservation, an inactive contract or a draft employee activates nothing;
- if that work email is already another account's login, no account is created and HR sees an error toast.

Per B5D-Q8, this is also how **existing** staff without an account get one: at their next contract, never in bulk.

### 2.5 Lists and review (B5-D §5.4, Q5, Q7)

The employee list gains two views next to Active and Inactive:

| View | Rows | Who |
|---|---|---|
| **Awaiting contract** | `awaitingContract()` | HR, general manager, super-admin (same as Inactive) |
| **Incomplete** | drafts | HR and super-admin only (not the general manager: a draft is HR's work in progress) |

- **Active** and **Inactive** never show drafts. Inactive still shows every complete inactive record, as before, so state C also appears there with an "Awaiting contract" badge.
- **Status badges:** Incomplete, Awaiting contract, Active, Inactive.
- A **"Review"** flag appears on drafts untouched for 90 days and on awaiting volunteers older than 60 days. "Untouched" is measured by `updated_at`.
- **Nothing is deleted or deactivated automatically.**

**Discard draft** asks first (B0 confirm), then deletes the draft and its Draft-type reservation. It is refused unless the record is a draft with no real contract and no account. It requires `employees.update` in city scope (B5D-Q7: "HR for that city"). Employees are hard-deleted, as today. The activity log records the event, with sensitive attributes redacted as for any employee.

### 2.6 Contract-draft reservations stay separate (step 4)

Unchanged:
- reserving a position from the create page (now also from a draft, B5D-Q6);
- the composer finding and upgrading that reservation;
- a reservation claiming no seat.

Upgrading it now also triggers the activation (§2.4); a draft must be completed first.

## 3. Security and privacy

- **No new route.** New Livewire actions are classified in `config/access-control.php`:
  - `saveDraftRecord` (create/update);
  - `confirmDiscardDraftRecord` and `discardDraftRecord` (update);
  - `canUseIncompleteView` (read-only).

  Each re-authorizes on the server.
- **City scope:** the draft minimum validates the governorate against the officer's scope (`authorizeScope`). A draft is opened only through `Gate::authorize('update')`.
- **Data exposure:**
  - the duplicate-ID message shows name and city only (Q9);
  - drafts are not in the directory, the org chart, counts or pickers (all filter `is_active = true`);
  - the Incomplete view is HR and super-admin only.
- **Pay:** untouched.

## 4. Verification

**Automated:** `tests/Feature/EmployeeOnboardingTest.php`, 28 tests, all passing.

| AC | Tests |
|---|---|
| AC-B5-01 | the minimum saves a draft (inactive, no account, email or token, saved-by); missing minimum rejected, gender and marital status not needed; another HR officer resumes and completes (→ C, not active); completing still needs gender and marital status; a draft saved again stays a draft; HR of another city gets 403; a complete record never goes back to draft; a resent save continues on the same record; duplicate national ID names the record and city; the weekly job leaves drafts and C alone |
| AC-B5-02 | draft not offered in the composer and refused by the server; awaiting volunteer offered; toggle cannot activate a draft or an awaiting volunteer, but still re-activates former staff; the browser cannot set `is_active` or `create_account` on a new draft **or on an existing draft**; a complete new record is not active and has no account; discard with confirmation removes draft and reservation; a complete record cannot be discarded; the first real contract activates and creates one account with role and reset email; a reservation upgrade activates; a reservation alone activates nothing; work email in use → no account, error toast |
| AC-B5-03 | existing rows default to `complete`; editing active staff changes nothing else and creates no account; HR can still create an account deliberately for an active employee; views keep drafts apart, and existing inactive staff stay under Inactive; the general manager cannot open Incomplete |

- **The tests catch real breakage:**
  - without the draft check in `ContractForm`, the composer test fails;
  - with `is_active` back in the form payload, the edit-path test fails.

  Both files were restored.
- **Full suite:** 928 tests, **all passing** (902 before B5).

**Rollout pilot** (task step 12), on a synthetic copy `C:/laragon/tmp/hc-hr-b5` with 282 legacy employees (200 active), 261 accounts and 316 contracts:

| Step | Result |
|---|---|
| `migrate` | 282 rows `complete`; still 200 active and 261 accounts |
| `migrate:rollback --step=1` | columns removed; 282 employees, 316 contracts, 1,616 leave balances, 191 media rows unchanged (no SQLite table rebuild, no cascade) |
| `migrate` again | same as the first run |

**Browser** (synthetic copy, launch entry "hc-hr B5 (synthetic SQLite copy)", port 8399, HR user):

| Check | Result |
|---|---|
| AR, desktop: create page | Panel «متطوع جديد» with live checklist; Save / Save draft / Cancel |
| Fill only the draft fields, click «حفظ كمسودة» | Toast "draft saved…"; reservation prompt; guard clean. DB: `draft`, inactive, gender empty, no token, saved-by set, 0 accounts |
| Skip → edit page | «سجل غير مكتمل (مسودة)», "saved 23 Oct 2026, 10:42 by …", all sections open, Discard, Complete record, Save draft (disabled until changed) |
| Click "Gender" in the checklist | Focus on the gender radio |
| Fill gender, marital status, nationality → «إكمال السجل» | One toast "Record completed…"; panel «جاهز، بانتظار العقد» with Create contract |
| List, Awaiting contract view | Only that volunteer, badge «بانتظار العقد» |
| AR, phone 375×812, create page | Save / Save draft / Cancel in M1's sticky bar; no sideways scroll |
| EN, phone: duplicate national ID | "This national ID is already registered for Lina Haddad (Aleppo)." Nothing saved |
| EN, phone: new ID → Save draft; Incomplete view | Card "Omar Khoury · Incomplete"; no sideways scroll |
| Composer employee options (Aleppo) | Offers Lina (awaiting, #283); does not offer the draft (#284) |

The first contract activating an account was verified by tests (§4), not through the composer UI.

## 5. Decisions taken here (routine, reversible)

| Choice | Why |
|---|---|
| A draft accepts an empty nationality | B5D-Q3 says optional. Today's rule (`integer`) silently rejects an empty value, so it is made `nullable` for drafts only; completing a record keeps today's rule. |
| Inactive view = all complete inactive records (state C included, badged) | Keeps the existing Inactive list unchanged (AC-B5-03); Awaiting contract is the focused subset |
| Review flags use `updated_at` | No separate "completed at" column; a later edit restarts the clock |
| Discard needs `employees.update`, not `employees.delete` | B5D-Q7: "HR for that city"; HR roles may not hold the delete permission |
| The general manager does not see Incomplete | B5-D §5.4 lists HR, HR-SYR and super-admin for drafts |

## 6. Open points and limits

| # | Item | Owner |
|---|---|---|
| 1 | **Decided 2026-10-23 (product owner, for HR):** an "awaiting contract" volunteer is **not** activated by hand; only the first contract activates them (B5D-Q4). This also covers older inactive records that never had a contract: they are offered in the contract composer instead. Former staff keep the toggle. | Done |
| 2 | **Decided 2026-10-23 (product owner, for HR):** the composer offers active staff plus everyone awaiting a first contract (new volunteers and older records without one). Never drafts; former staff are re-activated first, as before. | Done |
| 3 | **Phase 2 autosave (B5D-Q10): built 2026-10-30**, see §8. Server-side only, never browser storage. Not yet checked in a browser or run through the suite on PHP 8.5 (§8.5). | QA |
| 4 | Live migration not run; run with the deploy only after authorization. Additive and reversible (§4 pilot). | Tech lead |
| 5 | National-number uniqueness at the database level is missing (found in B2, separate task); validation still refuses duplicates | Backend |
| 6 | Phone checks were in an emulated viewport. Clicks did not always register there, so some buttons were pressed with the keyboard. No real device, no screen reader. | QA / M1 |
| 7 | The phone section picker (M1) still says "the other sections unlock after you save the profile". This is accurate, since a draft save unlocks them, and sits next to the panel's fuller text. | Frontend (optional) |

## 7. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-B5-01** The approved minimum can be saved and resumed by an authorized HR user. | **Met** | §2.2; tests; browser AR create → draft → resume → complete |
| **AC-B5-02** Incomplete drafts cannot activate staff/accounts/contracts contrary to DEC-04. | **Met** | §2.3–2.4; tests incl. forged `is_active`/`create_account`, composer refusal, toggle; browser composer options |
| **AC-B5-03** Existing active/inactive employees and contract draft upgrades retain their expected behavior. | **Met** | §2.5–2.6, §5; tests; full suite green; migration pilot forward/back/forward |

**Formal review (QA engineer + technical lead): pending.** B5 is **not** marked done.

## 8. Phase 2: server-side autosave (DEC-04, B5D-Q10)

Date: 2026-10-30. Decision: [employee-lifecycle.md §5.6](employee-lifecycle.md), B5D-Q10, DEC-04 in [decisions.md](decisions.md).

### 8.1 What it does

| Rule (§5.6) | How |
|---|---|
| Server-side, debounced on a field or section change | `resources/js/employee-autosave.js` watches `infoForm` and calls the component's `autosaveDraft()` **2.5 s** after the last change, at once on a section (tab) change, and when the browser tab is hidden. One request at a time; whatever changed meanwhile is sent right after. |
| Only after the draft minimum is valid | The browser asks only once the 8 names, governorate, national ID and username are filled in. The server then runs **the same draft rules as "Save draft"** (`PersonalInfoForm::saveDraft()`): governorate in scope, national ID unique (and 11 digits if Syrian), username unique. If they fail it answers `incomplete`: nothing written, **no error added** (and errors an explicit save is showing are left as they were). |
| Always the same row | Edit page: the draft being edited. Create page: the first autosave creates the draft through `saveRecord`'s path (same cache lock, same `#[Locked] submissionToken` key), so every later autosave, and a later "Save draft" or "Save", updates that row. An autosave that finds an explicit save holding the lock does not wait: it answers `busy` and the browser tries again. |
| Same "Saved at hh:mm by …" | The status line in the onboarding panel (`partials/onboarding-status.blade.php`, `role="status"`, `aria-live="polite"`): **Saving…** / **Saved at hh:mm by {name}** / **Not saved yet: fill in names, governorate, national ID and username** (EN/AR in `employees.onboarding.autosave-*`). On a draft it starts with the stored "Saved {date, time} by {name}". |
| Never browser storage | Nothing is written to `localStorage` or `sessionStorage`; the draft is in the database or nowhere. |
| A complete record is never autosaved to draft | No status line, no autosave on a complete record. The server re-checks: a record that is no longer a draft (completed here, in another tab, or by a forged request) answers `complete` and nothing is written. |
| Never completes or activates | Autosave only calls `saveDraft()`, which keeps `record_status = draft`, never takes `is_active` or `create_account` from the browser, and creates no account, reset email or card token. |
| B2 leave guard | After a successful autosave the guard's baseline for `infoForm` becomes **what was sent** (`window.hcUnsaved.markSaved`), so leaving no longer asks; anything typed while the request was on its way still counts as unsaved. |

### 8.2 Behaviour choices

| Choice | Why |
|---|---|
| **The create page stays put after the first autosave.** No draft-position step, no redirect, no toast; the action is `#[Renderless]`, so nothing re-renders under the cursor. A link **"Open the saved draft for the other sections"** appears under the status line. | "Save draft" moves on (draft-position step, then the edit page) because the person asked to stop there. An autosave happens mid-typing: moving the page then would lose their place. The other sections need the edit page (their forms live on `EmployeeEdit`), so they stay locked on the create page and the link takes the person there when they choose; the B2 guard is already clean, so it asks nothing unless something is unsaved. |
| Explicit "Save draft" / "Save" after an autosave | Unchanged: they update the autosaved row, then show the draft-position step as before. Autosave stops once the form has made way for that step. |
| The photo is not autosaved | It is re-encoded and stored by an explicit save only (`saveDraft(withPhoto: false)`), not every few seconds. A picked photo therefore keeps the leave guard "unsaved" until Save draft or Save. |
| Errors | Silent when the minimum fails. The app's existing per-field checks (`#[Validate]` on phones, e-mail, URLs, dates) still run on whatever a request carries, so a malformed phone number can show its error after an autosave, as it already did on a section change. |
| The two save buttons became `<x-button>` | The legacy buttons went disabled on every request of the component; with autosave they flickered every pause and could swallow a click. `<x-button>` is busy only for its own action. |
| Cancel on the create page | After an autosave the draft exists: Cancel leaves it, listed under Incomplete, where Discard deletes it. The status line says it was saved. |

### 8.3 Security

- **New action `autosaveDraft`** on `employee.employee-create` (`employees.create`) and `employee.employee-edit` (`employees.update`), classified in `config/access-control.php`. No new route.
- **Re-authorized on every call:** create page, `employees.create`, then `Gate::authorize('update')` once the record exists; edit page, `Gate::authorize('update')` as `saveDraftRecord` does. Then `PersonalInfoForm::authorizeScope()` runs again inside the save. HR of another city gets 403. A governorate outside the officer's scope is "not saved yet" (part of the minimum), not a 403 while typing.
- The response carries status, time, the saver's name and, on the create page, the draft's edit URL: nothing the page did not already show.

### 8.4 Files

- `app/Livewire/Concerns/AutosavesEmployeeDraft.php` (new): silent run and result.
- `app/Livewire/Employee/EmployeeCreate.php`, `EmployeeEdit.php`: `autosaveDraft()`.
- `app/Livewire/Forms/Employee/PersonalInfoForm.php`: `saveDraft(bool $withPhoto = true)`.
- `resources/js/employee-autosave.js` (new), `resources/js/unsaved.js` (`markSaved`), `resources/js/app.js`.
- `resources/views/livewire/employee/partials/onboarding-status.blade.php` (status line), `record-save-buttons.blade.php`, `form.blade.php` (sections stay locked on the create page).
- `resources/lang/{en,ar}/employees.php` (`onboarding.autosave-*`, `sections-after-draft`).
- `tests/Feature/EmployeeAutosaveTest.php` (new).

### 8.5 Verification

**Not run yet.** The build machine's CLI is PHP 8.3 and the framework needs 8.4+, so the tests were written and read through, linted (`php -l`, Pint, `node --check`), but not executed. Run on the dev machine (PHP 8.5):

```bash
php artisan test --filter='EmployeeAutosaveTest|EmployeeOnboardingTest|PhoneEmployeeContractFlowTest'
composer test
```

`EmployeeAutosaveTest` covers: below the minimum writes nothing and shows no errors (also a taken national ID and an out-of-scope city); errors of an explicit save survive an autosave; the first valid autosave creates one draft and later ones (and a later Save draft) update that row, without redirect or toast; a draft's edit page updates that draft; a complete record is never autosaved (edit page, and a record completed on the create page even with a forged `showDraftPrompt`); HR of another city gets 403 (edit page, and on a draft the create page autosaved); no `employees.create` → 403; autosave never activates, never creates an account, sends nothing; `last_saved_by` is the autosaving officer; the status line shows on the create page until an explicit save.

**Browser check still to do** (AR and EN, desktop and phone): type the minimum, pause, see Saving… then Saved at; keep typing during a save; switch section; leave the page (no question once saved); open the saved draft from the link; screen reader announces the status line once per save.

