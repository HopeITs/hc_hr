# A5-M: Protect modal input and close without refreshing the page

Task A5-M (R-03, R-18, R-19) in [ux-prompts-v3.md](ux-prompts-v3.md). Prerequisite B0: `WithFeedback::confirm()` and the shared confirm dialog.
Status: **implemented on every editable dialog, awaiting review** (QA engineer + technical lead). Open: a screen-reader pass (§5).

## 1. Shared contract

| Piece | Where | Behaviour |
|---|---|---|
| `close-action` prop | `components/modal.blade.php`, passed through `components/modal/dialog.blade.php` | Name of the Livewire method that decides whether the dialog may close; it is called with the dialog's `id`. Set → **the backdrop is ignored** and Escape, the header close and Cancel all call `close()`, which asks that method. Unset → every route closes locally (read-only dialogs unchanged). |
| `close()` | modal Alpine scope | The one close route. `<x-modal.cancel-btn/>` and every migrated Cancel use `x-on:click="close()"`; none is a link or a submit. |
| `GuardsModalClose` | `app/Livewire/Concerns` | `closeBaselines` (`#[Locked]`, in memory, per dialog) set by `markClean($dialog)` when the form is filled. Dirty = normalized entries ≠ baseline: changed-then-restored is clean; `5`/`"5"`/`" 5 "`, `null`/`""`, `true`/`"1"`, `09:00:00`/`09:00` and a multi-select in any order are equal; `"001"` ≠ `"1"`. A chosen upload is always a change. Nothing goes to browser storage. |
| `requestClose($dialog)` / `discardChanges($dialog)` | the trait | Clean → close. Dirty → one confirmation: **Discard your changes? / Your changes have not been saved. / Keep editing / Discard changes** (AR: تجاهل التغييرات؟ / لم يتم حفظ تغييراتك. / متابعة التعديل / تجاهل التغييرات), keys `general.unsaved.*`. Only `discardChanges` clears the form. `$dialog` comes from the browser, so it must be in `guardedDialogs()` (else 404). Neither writes a record or navigates. |
| Hooks | override per component | `guardedDialogs()` (default `['showModal']`), `guardedDialogIsOpen()`, `guardedEntries()` (default `$this->item`), `closeGuardedDialog()` (default hide + reset validation). |
| Confirm dialog | `confirm-dialog.blade.php`, `feedback.js` | `confirm(..., cancelLabel:)` renames the dismiss button. Initial focus is on it; Escape or a backdrop click on the confirmation answers "no". When it closes, the dialog puts focus back on the control that asked (§4). |
| Labels | `leaves.modal.*` EN/AR | New: **Request leave / طلب إجازة** (entry button and title), **Submit request / إرسال الطلب**. Existing: **Edit leave request / تعديل طلب الإجازة**, **Save changes / حفظ التغييرات**. Approval flow untouched. |
| Access map | `config/access-control.php` | `requestClose`/`discardChanges` listed for each guarded component via `$closeGuard`, with the reason. |

To guard a new dialog: `use GuardsModalClose`, call `markClean('<dialog>')` just before showing it, override the hooks if it is not an `$item`/`showModal` module, and pass `close-action="requestClose"`. `ModalCloseGuardTest::test_every_editable_dialog_is_guarded_and_no_cancel_navigates` fails for any `<x-modal>` without it that is not listed as read-only. B2 should reuse `isDirty()` for navigation protection so the two guards never both prompt.

## 2. Save behaviour

- My Leaves save still goes `confirmSave` → `processSave` with the per-form submission token, so a retried or timed-out save returns the request it already created. Saves close and reset the form; a close queued behind a successful save finds the dialog closed and does nothing; after a failed save entries and errors stay and the guard applies.
- None of the guarded forms has a file input, so no temporary upload state exists to restore.

## 3. Consumer inventory

| Consumer | Kind | State |
|---|---|---|
| `employee/leave/index` request (new/edit) | editable | Guarded |
| `employee/leave/index` details | read-only | Ordinary close |
| `employee/leave/all` request form, decision | editable | Guarded; Cancel no longer links to `leaves.all` |
| `employee/leave/all` details | read-only | Ordinary close |
| `employee/leave/balance` adjustment | editable | Guarded; Cancel no longer links to `leaves.balance`; reopens from defaults |
| `contract/contract-renew-modal` | editable (period) | Guarded |
| `basics/*` (annual-leave-rule, checklist-item, city, contract-type, course-category, department, job-level, leave-type, termination-reason) | editable | Guarded |
| `program-management/centers-module`, `default-deliverables-module` | editable | Guarded |
| `badges/badges-module` | read-only | Ordinary close |
| `access-management/index` assigned users | read-only | Ordinary close |
| `employee/leave/form` (`UpdateLeaveRequest`), `program-management/positions-module` | unreachable | Not mounted by any route or view; candidates for removal |

## 4. Defects found and fixed while completing A5-M

1. **The guard was inert.** `<x-modal.dialog>` forwarded `close-action` as a plain HTML attribute, so `<x-modal>` never received it: the backdrop still closed and every route bypassed the check. The earlier tests had never run (PHP 8.3 sandbox); on PHP 8.5 they caught it.
2. **"Keep editing" read "Cancel".** Blade does not compile `@js()` inside a component tag's attribute, so Alpine's `x-text` was a syntax error. The default label now comes from the store's server-fed defaults. Found by the browser run.
3. **Escape stopped working after "Keep editing".** Cancel is disabled while its request runs, so focus fell to `<body>` before the confirmation opened and was "returned" there. The dialog now takes focus back when the confirmation closes. Found by the browser run.
4. Two existing tests failed on the earlier commit (`AccessibilityTest` close-button markup, `LivewireMutationCoverageTest` unclassified actions); both fixed.

## 5. Acceptance evidence

Automated (PHP 8.5, `php artisan test`): `ModalCloseGuardTest`, plus A5-M cases in `LeaveSelectorStateTest`, `LeaveRequestWorkflowTest`, `ContractRenewalTest` — pristine/dirty/restored, each route, discard, reopen clean, edit reopens saved values, no record written, save then queued close, whitelist of dialog names, locked baseline, normalization, labels EN/AR, inventory.

Browser (Chromium via Playwright, synthetic SQLite data, `docs/ux/evidence/a5m/`): My Leaves request dialog in EN/LTR and AR/RTL at 1280×800 and 390×844, plus the HR departments dialog in EN and AR: **112/112 checks passed**, twice in a row (`results.json`, screenshots, `e2e.mjs` to rerun). An earlier run had one Arabic-phone failure on changed-then-restored that did not reproduce; if it recurs, look at Escape pressed while a deferred `wire:model` sync is still in flight. Afterwards the e2e database held 0 leave requests and only the seeded departments.

| AC | State |
|---|---|
| AC-A5-M-01 backdrop | Passed: a dirty dialog stays open on a backdrop click, with its entries kept (leave and departments, EN and AR). |
| AC-A5-M-02 clean close | Passed: on a pristine form, Escape, the header close and Cancel all close it with no confirmation. The changed-then-restored case passed in the evidence runs. |
| AC-A5-M-03 one confirmation, focus | Passed: each route shows exactly one confirmation with the A5-M wording, focus starts on Keep editing, and Escape or Keep editing keeps the dialog and its entries with focus back inside. |
| AC-A5-M-04 discard | Passed: Discard closes the dialog and a reopened request starts clean. |
| AC-A5-M-05 no reload, scroll, focus | Passed: no document request, no navigation, an in-page marker survives, and focus returns to the opener. Weak point: the scroll check reads 0 → 0. The page is locked while the dialog is open, so this proves closing does not jump, not that a scrolled page stays put. |
| AC-A5-M-06 saving | Server-side tests (queued close, failed save keeps entries, submission token). Not exercised in the browser. |
| AC-A5-M-07 EN/AR, desktop/phone, keyboard, screen reader | EN/LTR and AR/RTL, desktop and phone, all by keyboard: passed. With a keyboard-height viewport, Cancel is still reachable (`*-03-keyboard.png`). **Screen-reader pass not done** (no AT in the sandbox). |
| AC-A5-M-08 labels, no write | Passed: the title is Request leave / طلب إجازة and the button Submit request / إرسال الطلب. Closing and discarding wrote no record (DB counts above, plus the server tests). |

## 6. Open before A5-M can close

- A screen-reader pass (NVDA or VoiceOver) on the leave dialog and its confirmation, EN and AR.
- Review. B2 to reuse `GuardsModalClose::isDirty()`.
