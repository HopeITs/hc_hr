# B2 · Protect user input and make save results clear: implementation notes

Status: **implemented and verified, awaiting review** (QA engineer + technical lead). Not marked done until reviewed.
Date: 2026-10-23. Task: B2 in [ux-prompts-v3.md](ux-prompts-v3.md) (Phase B). Consumers: B5, M1, G-B, D1.

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision | `hr-ux-by-rafi` @ `1b87bdbc` (= `main` with B1, B6, G-A) |
| B1 | Done on `main` (`d4944019`, [b1-navigation-inventory.md](b1-navigation-inventory.md)) |
| B0 | Shared confirm dialog and toasts ([feedback-contract.md](feedback-contract.md)) |
| A5-M | Dialog close guard `GuardsModalClose` ([a5m-implementation.md](a5m-implementation.md)) |
| G-A condition (item 1) | "B2 must not reuse `GuardsModalClose` until the Arabic save → reopen → Escape check passes." **Checked first, passed** (§2). |

## 2. G-A item 1: Arabic Escape after a save (checked before reuse)

Synthetic copy (`C:/laragon/tmp/hc-hr-b2`, launch entry "hc-hr B2 (synthetic SQLite copy)", port 8398), staff user, `/ar/leaves`, browser pane about 510×382, real key presses:

| Run | Steps | Result |
|---|---|---|
| 1 | Submit a valid request (confirm, Save), reopen with Enter, Escape | Dialog closed, **no "discard?" prompt**, focus back on «طلب إجازة» |
| 2–4 | Reopen, Escape (run 3 with only 1 s between) | Same |
| 5 | Second save, reopen after 0.7 s, Escape | Same |

**5 of 5 passed.** The G-A failures were not reproduced; they fit the timing flakes of a single-threaded `php -S` server that G-A itself suspected. B2 reuses the guard only through its existing close route (§3.3); `GuardsModalClose` itself is unchanged.

## 3. What changed

### 3.1 Field-linked error summary (step 1, AC-B2-01)

`<x-forms.errors-validation-list>` keeps its B0 rule (it lists only errors whose inline message is not on screen) and now makes **each entry a button** that takes the user to the field:

1. If the field is on screen, it is focused and scrolled into view.
2. Otherwise the entry opens the field's **tab or section**: a Livewire method (`reveal="setActiveTab"` on the employee form) and a `reveal-field` window event (the contract composer listens for it), then focuses the field once it renders.

The field is the control bound to the error key (`wire:model`, any modifier, or `name`); for Alpine-bound fields (the composer) it is the first control next to the inline message. select2 boxes get focus on their visible box.

| Form | Map from field to tab/section | Where |
|---|---|---|
| Employee create/edit (11 tabs) | `infoForm.*` → Profile, except `PersonalInfoForm::CONTACT_FIELDS` → Contact; `educationForm.*` → Education, etc. | `livewire/employee/form.blade.php` |
| Contract composer (create, renew, edit) | `DrivesContractComposer::errorSections()`, built from the existing `sectionOfField()` | `_screen.blade.php` |
| Leave dialogs, positions | single section: focus only | unchanged views |

Two defects fixed on the way:

- `EmployeeCreate::tabForErrors()` knew only 4 of the 11 Contact fields, so an error on, say, a malformed personal phone jumped to Profile. It now uses the shared `CONTACT_FIELDS`.
- The summary re-checked what was on screen only after a server round trip. Collapsing a composer section (browser-only) hid its messages without listing them. It now also re-checks after clicks and key presses.

### 3.2 Leaving with unsaved changes (step 2, AC-B2-02)

New `resources/js/unsaved.js`: one registry of "is anything unsaved?" checks, consulted before the page goes away.

| How the user leaves | What happens |
|---|---|
| `wire:navigate` link, Livewire redirect | One confirmation through the B0 dialog, in **A5-M's wording and keys** (`general.unsaved.*`: Discard your changes? / Your changes have not been saved. / Keep editing / Discard changes; AR: تجاهل التغييرات؟ / لم يتم حفظ تغييراتك. / متابعة التعديل / تجاهل التغييرات). Focus starts on Keep editing. |
| Browser Back/Forward (Livewire history) | The step is undone and the same question asked. Keep editing: page, URL and entries stay. Discard: the user's own step is taken again, so history stays as it was. |
| Back while an editable dialog is open | Handed to **that dialog's own close route** (A5-M `requestClose`): clean → it closes; changed → A5-M asks once. The page does not navigate. Only one question is ever on screen. |
| Reload, close tab, plain links, form posts | `beforeunload`: the **browser's own** prompt. Its wording is set by the browser and cannot be the custom text. |

What registers:

- **`<x-forms.dirty-gate>`** for every page form it watches (all employee tabs, create and edit). The baseline now lives in the registry per component and form, so it **survives the tab re-rendering**. Before B2, coming back to a tab re-took the baseline from the unsaved entries, which locked that tab's Save button and forgot the work. In create mode the buttons stay always-enabled as before, but leaving still asks.
- **The contract composer.** Its baseline is taken at the user's first pointer or key press, because seeding and cascades rewrite the form while it loads. Dirty = the payload differs from that baseline. It never counts as dirty while sending, so a successful save's redirect is not stopped.
- **Editable `<x-modal>` dialogs** (those with `close-action`). For Back they defer to A5-M as above. For `beforeunload`, which is synchronous and cannot ask the server's `isDirty()`, an open dialog counts as changed once the user typed or picked something in it.

Reset rules:

- A failed save keeps the guard (no `form-saved`).
- Only a confirmed successful save (`form-saved`, now also sent by `EmployeeCreate`) or an explicit Discard clears it.
- Changed-then-restored counts as unchanged.
- Nothing goes to browser storage.

### 3.3 Failures keep input; uploads explained (step 3, AC-B2-03)

- Inputs on validation failure: already kept by Livewire (form state lives on the component). Proven by tests and the browser runs below.
- Chosen files: `<x-forms.file-input>` now says under a staged file: "Not saved yet. This file stays attached while this page is open, even if saving fails. If you leave or reload the page, choose it again." (AR included). That is what really happens: a Livewire temporary upload survives failed saves in the page state. It does not survive leaving the page, and the server clears temporary uploads after 24 h (`config/livewire.php`). The text promises nothing more.

### 3.4 Pending controls and duplicate protection (step 4, AC-B2-03)

| Write | Pending control | Server-side duplicate protection |
|---|---|---|
| Leave request (A3) | already disabled | submission token, unchanged |
| **Contract create / renewal** | composer's `submitting` (existing) | **New:** `ContractCreate::$submissionToken` (`#[Locked]`, one per opened composer). Under a cache lock on the token, a consumed token redirects to the contract it created instead of writing again. While a first send is still running, a second one gets a toast "This contract is still being saved…" (EN/AR) and writes nothing. A consumed token leads only to a contract the user may `view`. |
| Contract edit | composer `submitting` | an update with the same values is idempotent |
| Employee create | `<x-primary-button>` `wire:loading.attr="disabled"` (existing) | DB unique index on `username` plus validation. A replay fails with "username taken" instead of a second row (see §6). |
| Employee tabs, settings dialogs | same button behaviour | updates, idempotent |

**Undo:** none added. No new reversible operation was approved; the existing B0 undo for deletes is unchanged.

## 4. Security and privacy

- No new public Livewire method. Revealing a tab reuses the existing `setActiveTab`, which only changes which tab renders; every save still re-authorizes. The submission token is `#[Locked]` (tested).
- The error summary renders only messages already in the error bag for this user. Nothing about other records, pay or cities is added to any payload. The registry holds only what the page already has in `$wire`.
- A consumed token cannot open someone else's contract: `createdBy()` checks `can('view')` (tested with a contract in another city).

## 5. Verification

**Automated:** `tests/Feature/UnsavedWorkProtectionTest.php`, 13 tests, all passing:

| AC | Tests |
|---|---|
| AC-B2-01 | other-tab error links to Contact; profile error links back to Profile; all 11 contact fields pick Contact in create; composer `form.salary` links to Pay |
| AC-B2-02 | successful create sends `form-saved`; failed save keeps entries, sends no `form-saved`, writes nothing; edit and create forms register with the guard |
| AC-B2-03 | staged file shows the reattachment note; **a re-sent contract submit creates one contract** (and one position seat) and lands on it; a second composer creates a second contract; a send while the first holds the lock writes nothing; the token cannot be forged; a token never leads to a contract the user may not see |

**The duplicate test catches real breakage:** with the token check disabled, `test_a_resent_contract_submit_creates_one_contract` fails; restored.

**Full suite:** 799 tests (786 before the B2 tests), 1 failure: `ContractPdfRendererTest::test_a_remote_chrome_is_served_the_page_without_a_public_url`. It also fails on this machine without B2 (a `public/temp` directory exists here) and is A6's area. No new failure.

**Browser** (synthetic copy, HR and staff users, real clicks and key presses, EN and AR):

| Check | Result |
|---|---|
| Employee edit, unchanged → Back to employees | Leaves, no question |
| Typed address → Back to employees | Question in A5-M wording, focus on Keep editing. Escape keeps page and text. Discard leaves. |
| Typed on Profile → Contact tab | Still counted as unsaved; Contact's Save stays enabled |
| Save from Contact with errors on Profile | Summary lists them; clicking «National Number…» opens Profile and focuses the field; typed address kept; guard still on |
| Fix and save | Success toast; guard cleared; Save locked; leaving asks nothing |
| `beforeunload` decision | clean: no prompt; changed: prompt; changed then restored: no prompt |
| Contract composer: untouched Cancel | Leaves, no question |
| Composer: pick a location, Cancel | Question; Escape keeps the composer with the choice |
| Composer: server errors, Assignment collapsed | Summary appears; an entry reopens Assignment and focuses the field (the position error focuses Program, which must be chosen first) |
| AR, leave dialog open with a reason typed, Back | URL and dialog stay, A5-M asks once («تجاهل التغييرات؟»); Escape keeps the reason; Discard closes; focus returns to «طلب إجازة»; the next Back goes to the list with a matching URL and title |
| AR, employee edit typed, Back ×2 | Asks each time; Keep editing keeps the text; Discard lands on the list |

A defect found and fixed by these runs: undoing Back by adding a history entry left Livewire's own record of the current page out of step, so the next Back changed the URL without changing the page. The step is now undone by stepping the browser back, which Livewire follows.

## 6. Limits and hand-offs

- **Browser prompt wording** for reload or tab close is the browser's; it can't show the A5-M text. Native prompts were not clicked in the test browser: the handler's decision was checked directly (§5).
- **Back to a page loaded outside Livewire's history** (the first page of a visit, then a full reload) is a native navigation: the browser's prompt applies, not ours.
- **Dialogs on reload:** "changed" means typed or picked since opening, so a dialog changed and then restored still gets the browser prompt on reload. Back and Escape use A5-M's exact check.
- **Employee create replay:** a create retried after a lost response is refused by the unique `username` ("already taken"), not sent to the created employee. B5 (step-by-step registration, drafts per B5-D) should give this form its own token.
- **Out of scope, flagged as a separate task:** migration `2026_07_31_000004` dropped the database unique index on `employees.national_number`, and nothing fills the `national_number_index` blind-index column meant to replace it. Only validation now prevents two employees with one national number.
- **Test environment quirk:** on the `php -S` copy, `/en/...` URLs redirect to https (default locale hidden); use unprefixed English URLs there.
- **Not done here:** screen-reader pass, real phone. M1 should cover the summary and the confirmation on a phone.

## 7. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-B2-01** Each summary error reaches its field even in another tab/section. | **Met** | §3.1; tests; browser: employee Profile from Contact, composer Assignment from collapsed |
| **AC-B2-02** Unchanged forms do not warn; changed forms warn and successful saves reset the guard. | **Met** | §3.2; tests; browser: links, Back (EN/AR), composer, dialogs, `beforeunload` decision |
| **AC-B2-03** Failures preserve normal inputs and explain any file reattachment; retry does not duplicate records. | **Met** | §3.3–3.4; tests incl. lock and forged-token cases; the duplicate test fails without the fix |

**Formal review (QA engineer + technical lead): pending.** B2 is **not** marked done.
