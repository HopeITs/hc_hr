# A5: Shared dialogs and controls, keyboard and assistive tech

Task A5 (R-19) in [ux-prompts-v3.md](ux-prompts-v3.md). Started 2026-10-20 on `dd33bdb1`.
Status: **implemented and committed, awaiting review** (QA engineer + technical lead). The real screen-reader pass is still **pending**, so A5 is **not** done.

## 1. Prerequisites

| Input | State | Effect on A5 |
|---|---|---|
| X1 | Baseline measured ([evidence/x1](evidence/x1/summary.md)) | None. A5 changes no query or payload. |
| X2 | Instrument ready, **0 participant sessions** ([usability-baseline.md §10](usability-baseline.md)) | The technical lead must choose (a) wait for sessions or (b) merge and disclose "no pre-change baseline" **before A5 merges**. |

Task A and C stop points are unchanged. The scripts still match, but labels changed (see §5), so they need a version note.

## 2. What changed

| Area | File | Change |
|---|---|---|
| Modal semantics | `components/modal.blade.php` | Panel is `role="dialog" aria-modal="true"`, named by `labelledby` or `label`. |
| Focus trap, inert, scroll lock | same | `x-trap.inert.noscroll.noautofocus` from the Alpine focus plugin (bundled with Livewire 4; no new package). Replaces the hand-written Tab handler. |
| Initial focus | same | Goes to `[autofocus]`, else the first field, else the panel. Retries until the panel is painted. |
| Return focus | same | Returns to the last control focused before opening. The Livewire opener is disabled during the request, so `x-trap` alone landed on `<body>`. Falls back to `#main-content`. |
| Focus lost on re-render | same | When a re-render replaces the focused field (changed `wire:key`), focus goes back to the replacement or the first field. |
| Escape | same | Listens on the dialog, not the window. It is ignored when the confirm dialog is open or an inner widget already used Escape (the select2 list). |
| Dropdowns in dialogs | `modal.blade.php`, `forms/select2-input.blade.php` | select2 renders into a `wire:ignore` holder inside the dialog, so it stays in the trap. The first attempt used the dialog root, and Livewire's morph then reset the leave type to "Select". That was found and fixed in §4. |
| Dialog title and close | `modal/dialog.blade.php` | `<h2 id="{id}-title">` names the dialog. New close button with a translated name (`general.a11y.close-dialog`), 44×44 px. |
| Confirm over a modal | `confirm-dialog.blade.php` | It removes the `aria-hidden` the modal's inert set on it, and its focus goes to Cancel even when the first paint is late. |
| Duplicate dialog role | `access-management/index.blade.php` | The inner `role="dialog"` was moved to the shared modal (`labelledby`). |
| Radios | `forms/radio-input.blade.php` | Stable unique id (`radio-{model}-{value}`) instead of the shared `id="btn"`. `name` defaults to `wire:model`, giving one tab stop and arrow keys. The label is 44 px tall. |
| Radio groups | new `forms/radio-group.blade.php` | `fieldset`/`legend` with an optional linked error. Used for leave day/hour (index, form, all), the decision Accept/Reject, the balance operation/unit, and leave-type gender/marital status. |
| Nested labels | `employee/leave/all.blade.php` | Removed `<label>` wrappers around radio-input (invalid nesting). "Leave Type / Day / Hour" there are now translated. |
| Field names | `forms/input-label.blade.php`, `forms/select2-input.blade.php`, `forms/time-picker.blade.php` | A label with `for` gets the id `{for}-label`. select2 and the time picker are named by it. Before, select2 was named only by its value ("Select"), and the time box had no name. |
| Errors | `forms/input.blade.php`, decision modal | The input derives its id and error key from a plain `wire:model`. The reject reason is named and its error linked (`aria-invalid`, `aria-describedby`). |
| Icon buttons | `components/table/{decision,show,edit,export,delete,renew}-btn` | Translated `aria-label`. 44 px on phones (`max-sm:`). |
| Table bar (vendor views) | new `resources/lang/ar.json` | Arabic for "Refresh table", "Columns", "Filters", "Clear search", "Copy", "Reordering", "Polling", "Trashed". "Showing" is left out on purpose, because the pagination sentence around it is English (A4). |
| Reduced motion | modal, dialog | `motion-reduce:transition-none` / `transform-none`. Markup only; not checked visually. |

No schema, route, policy, permission, salary or data change. No server action was added or changed.

## 3. Acceptance evidence

Browser: the X2 synthetic SQLite copy (`127.0.0.1:8391`, `*@example.test` personas, no real data). Every check was driven by keyboard events in the built-in browser, with focus and accessible names read from the DOM after each key.

### AC-A5-01: open, navigate, close, return focus (EN/AR); background unreachable

| Run | Steps | Result |
|---|---|---|
| EN, staff, *Add New* leave (Task A) | Enter on *Add New* | Dialog "Add New" opens. Focus is on **Type (required)**. `<html>` overflow is hidden. Navbar and footer are `aria-hidden`. |
| | Tab ×N, Shift+Tab | Type → Alternative Employee → dates → Reason → Save → Cancel → Close dialog → **wraps to Type**. Focus never leaves the dialog. |
| | Enter on Type, type "Annual", Enter | The list opens inside the dialog. Annual stays selected. Focus returns to Type ("Type, Annual"). |
| | Escape with the list open | Closes **only the list**. The dialog stays open. |
| | Escape | The dialog closes. Focus is back on **Add New**. Scroll is unlocked. |
| AR, manager, decision (Task C) | Enter on the row's **قرار الإجازة** button | Dialog "قرار الإجازة" opens. Focus is on **قبول**. The close button sits on the left (RTL end). |
| | Escape | The dialog closes. Focus is back on the same row button. |
| AR, confirm over the decision dialog | `$confirm` opened on top | Focus goes to **إلغاء**. The decision dialog is `aria-hidden`; the confirm is not. |
| | Escape, Escape | 1st: the confirm answers *no*, the dialog stays open, focus is back on **قبول**. 2nd: the dialog closes, focus is on the row button. |

### AC-A5-02: unique stable radio ids and names

| Run | Result |
|---|---|
| EN Task A, Annual | `radio-formtype-day` "Day" / `radio-formtype-hour` "Hour", group "Leave Type", one `name`. ArrowRight checks Hour, and focus stays on it after the Livewire update. Each label is 44 px tall. |
| AR Task C | `radio-formstatus-1` "قبول" / `radio-formstatus-2` "رفض", group "الإجراء (مطلوب)". ArrowDown checks رفض. |
| Tests | `AccessibilityTest`: unique and stable ids, shared name, label `for` matches, explicit ids kept, no radio nested in a second label. |

### AC-A5-03: buttons and errors named; focus visible at phone size and zoom

| Run | Result |
|---|---|
| AR icon buttons on *All Leaves* | Unnamed icon buttons: **0**. Names: تحديث الجدول, الأعمدة, قرار الإجازة, عرض التفاصيل, تعديل. |
| AR reject with empty reason | Field "سبب الرفض (مطلوب)" has `aria-invalid=true` and is described by "خطأ: حقل سبب الرفض مطلوب." The Save button's name in the accessibility tree is "حفظ". |
| 375×812, root text 200%, EN and AR | Inside the dialog: no horizontal scroll, the focused field is fully visible and not covered (hit test). Close button and radio labels are 88 px (44 px × 2). |

## 4. Defects found while verifying

| Found | Owner | State |
|---|---|---|
| The first dropdown fix (render select2 into the dialog root) let Livewire's morph reset the leave type to "Select". | A5 | **Fixed** (`wire:ignore` holder). Checked against `dd33bdb1`, where Annual sticks. |
| Focus fell to `<body>` on open (disabled opener) and after a field re-render. | A5 | **Fixed**. |
| At 375 px with 200% text, the **page** is wider than the screen before any dialog opens (467 px EN, 559 px AR). In AR the closed side menu is 512 px. A dialog opened there is then wider than the visible screen. | **M1** (app shell) | Open. Not caused by A5. |
| After the decision dialog closes, the URL keeps `?leave=64&mode=decision`. | A5-M / B1 | Open. |
| Escape, backdrop and Cancel still close dirty forms without asking. | **A5-M** (shared contract) | Not in A5. |
| select2 fields do not get `aria-invalid`/`aria-describedby` (wire:ignore). Their inline error is still announced (`role=alert`). | A5 follow-up or B0 | Open, limitation. |

## 5. Checks

- `php artisan test --filter=AccessibilityTest`: 16 passed (7 new).
- Full suite on PHP 8.5.11: **604 tests, 600 passed, 4 failed**. The 4 also fail on clean `dd33bdb1`: ContractAutoRenewalWorkflow, ContractCreatePerformance, ContractPayClause (PDF), ContractRenewal. No new failure. `AccessManagementTest` asserted the modal's exact class strings and was updated to assert the dialog's name instead.
- `pint --dirty`: passed. No JS bundle change (Blade/Alpine only), so `npm run build` is not needed.

## 6. Still required before "done"

1. **Real screen-reader pass** (NVDA + Chrome on Windows; TalkBack on a phone if possible) on Tasks A and C in EN and AR. The DOM checks above do not replace it.
2. Visual check of reduced motion and contrast of the close button (`neutral-500` icon on white).
3. Arabic content review of the new strings (`إغلاق النافذة`, `عرض التفاصيل`, `ar.json`) by the A4 reviewer.
4. Technical lead decision (a)/(b) on the missing X2 baseline, then review and merge.

## 7. Handoff

- **A3-R:** the leave type selector now keeps focus and its name inside the dialog. The selector-revert symptom seen during A5 was A5's own regression and is fixed. Diagnose the original A3-R reports on this base.
- **A4:** the table bar strings in `ar.json`, and the English "Showing … to … of" pagination.
- **A6:** dialogs on the contract pages get the same modal behaviour. The composer's own radios already use `role=radiogroup` with wrapping labels.
- **B0:** confirm-over-modal stacking and Escape order are now defined (confirm first, then dialog).
- **A5-M:** builds the dirty-form guard on top of the single Escape/close path (`show = false`, the close button and `modal.cancel-btn`).
