# A3-R: Leave type selector keeps its value

Task A3-R (R-04) in [ux-prompts-v3.md](ux-prompts-v3.md). Started 2026-10-21 on branch `a3-r` (base `99ce2e1d`, A5 merged as `d8effd8c`).
Status: **implemented, not committed, awaiting review** (QA engineer + technical lead).

## 1. Prerequisites

| Input | State | Effect on A3-R |
|---|---|---|
| A5 | Implemented and merged, awaiting its screen-reader pass ([a5-implementation.md](a5-implementation.md)). Its handoff says the revert it caused itself is fixed. | A3-R was reproduced on the post-A5 base. Focus and naming inside the dialog are A5's and were not changed here. |

## 2. Reproduction (before the fix)

Browser: a disposable copy of the X2 synthetic SQLite fixture (`C:/laragon/tmp/hc-hr-a3r`, `127.0.0.1:8392`, launch entry "hc-hr A3-R (synthetic SQLite copy)"). The personas were `staff.alp@example.test` and `hr.alp@example.test`. No real data was used. The list was driven with keyboard events. After each server round trip, the visible select2 text, the `<select>` value and `$wire.form.leave_balance_id` were read.

**Correction to the task's scope.** The staff dialog is written inline in `livewire/employee/leave/index.blade.php`. `form.blade.php` is rendered only by `UpdateLeaveRequest`.

| Step (AR, then EN) | Before |
|---|---|
| Open *Add New* | The type box is **blank**: the value is `null`, and `select.val(null)` selects nothing. The placeholder is not shown. |
| Pick Annual, then Day/Hour, dates, alternative employee, failed save | Annual **stays**: 1 request per pick. |
| Annual → Sick → Annual | Stays. |
| Pick the placeholder ("clear") | **The placeholder's text reaches the server as the id.** `form.leave_balance_id = "اختيار"` (EN: `"Select"`). The `<option>` has no `value=""`, so its value is its own text. |
| Edit an existing request | Prefilled correctly (server holds int `13`, DOM holds `"13"`). |

The exact "Annual reverts to the placeholder" report was **not** reproduced on this base. Before A5, only the A5 own regression showed it. The causes found are on the same path:

1. The placeholder has no value. Its locale text was sent as an id, and `null` displayed as an empty box. This is why the box can look like it went "back" to nothing.
2. The leave-type wrapper was keyed by the selected value (`container-leave-type-{leave_balance_id}`, also on the alternative employee). Every pick made Livewire discard the `wire:ignore` subtree and re-run select2 from the server HTML, where the placeholder carries `selected`. The value then had to be restored from the entangled value, during init. This rebuild is the fragile path behind the reported revert. It also left the old select2 instance undestroyed each time.
3. `select.val() !== newValue` compared `'13'` with `13`. It was harmless but always true, so every server update re-triggered select2.

## 3. What changed

| File | Change |
|---|---|
| `components/forms/select2-input.blade.php` | Values are normalized **once**, in `normalize()`: `''`/`null`/`undefined` become `null`, and ids become strings. `chosen()` reads the selected option and returns `null` for any option without a `value` (the placeholder). `show()` selects the placeholder for `null` instead of showing a blank box. The `change` handler and the `$watch` compare normalized values and write only on a real change. |
| `livewire/employee/leave/index.blade.php` | The leave-type and alternative-employee wrappers are keyed by mode (`edit`/`new`), as `form.blade.php` already is. They are no longer keyed by the value. New ↔ edit still rebuilds the field, so the placeholder's disabled state stays correct. |
| `tests/Feature/LeaveSelectorStateTest.php` (new) | 5 Livewire tests: edit prefill, new-after-edit has no stale state, a string id selects, clearing deselects (EN and AR), and saving after a clear is rejected with no row written. |
| `.claude/launch.json` | A dev launch entry for the disposable A3-R fixture copy (port 8392). Not app code. |

The shared component is used by about 95 fields. No field uses `multiple`, so the single-value normalization covers all of them. Other placeholders that are valueless (`form.blade.php`, `all.blade.php`, the others) are now also sent as `null`.

No schema, route, policy, permission, salary, calculation or server-action change. Authorization in `create`/`edit`/`processSave` is untouched.

## 4. Acceptance evidence (after the fix, same fixture)

### AC-A3-R-01: the selected balance stays correct after server updates (AR and EN)

| Run | Result |
|---|---|
| AR, staff, *Add New* | Opens on **اختيار** (`wire = null`). Annual → `سنوية`, `wire "13"`, Day/Hour group shown. Hour → still `سنوية`. Placeholder → `wire null`, group gone. Annual again → `سنوية`. |
| EN, staff | Opens on **Select**. Annual → Hour → still `Annual / "13"`. Placeholder → `wire null`. |
| Requests | Exactly **one** `$set form.leave_balance_id` per pick. The other request seen in the log is the notification bell's `updateCount`. Only one `.select2` exists for the field throughout. |

### AC-A3-R-02: editing preserves the value, reopening a new form is clean

| Run | Result |
|---|---|
| AR, edit the pending request (Annual, id 4) | `سنوية`, `wire 13`, the placeholder is disabled, the Day/Hour group is shown, and dates/reason are prefilled. Annual → Sick → Annual inside the edit stays correct. |
| Pick Annual, close (Escape), *Add New* | Placeholder, `wire null`, no Day/Hour group. |
| Edit, close, *Add New* | Placeholder enabled, `form.id null`, empty reason, `wire null`. |
| Navigation | Switching AR → EN (full load) and *All Leaves* after re-login both start clean. |

### AC-A3-R-03: a second shared-selector form still works, and requests do not multiply

| Run | Result |
|---|---|
| AR, HR-ALP, *All Leaves* → *Add New* (`form.employee_id`, `.live`, `<option value="">`) | Employee → `إلياس عطالله` / `"31"`, 1 request. The leave-type list reloads for that employee, and Annual → `سنوية` / `"200"` with the Day/Hour group. Clearing the employee → `wire null` for the employee **and** the leave type (the component's own `updatedFormEmployeeId`), 1 request. |

### Slow connection (requests still in flight)

Same fixture. Every Livewire response was held for 2.5 s by wrapping `window.fetch` in the page, for inspection only. The next action started about 0.6 s after the first request had been sent.

| Run | Result |
|---|---|
| AR: pick Annual, and change the start date in the same instant | Sent as one request (`leave_balance_id "13"` + `start_at`). After the response: `سنوية`, the date kept, the Day/Hour group shown. |
| AR: pick Sick, then Annual while Sick's request is in flight | Two requests in order (`"14"`, then `"13"`). It ends on **`سنوية` / `"13"`** with the group shown. No flash back to Sick or the placeholder. |
| AR: Sick in flight, then the alternative employee, then Annual | The second request carries `alternative_employee "199"` + `leave_balance_id "13"`. It ends on `سنوية` and `آدم حنا`, and the date is kept. |
| AR: Sick in flight, then close the dialog with Escape, then *Add New* | It reopens on `اختيار`, `wire null`, empty date and alternative employee. No stale state. |
| EN: Full Sick, then Annual while Full Sick's request is in flight | Ends on **`Annual` / `"13"`** with the group shown. |

Automated: `php artisan test --filter=LeaveSelectorStateTest` passed **5/5** (35 assertions). These tests cover the server side only. The UI fix is proved by the browser runs above.

## 5. Found, not fixed (outside A3-R)

| Found | Owner |
|---|---|
| Switching from Annual + **Hour** to a non-annual type keeps `form.type = 'hour'`, and the confirmation then shows hours for that leave. Not changed, because the task keeps policy and calculation out of A3-R. | **A3** (with A3-D) |
| *Save* opens the confirmation with empty date/time ("Duration: 9 E…" for empty times) before validation. | A3 / B2 |
| `all.blade.php` placeholder "Select Employee…" is hard-coded English in Arabic. | A4 |
| The form's `wire:submit.prevent="save"` has no `save()` method. The button uses `confirmSave`. Enter in a text field may therefore error. Not tested. | A3 / B2 |
| select2 fields still get no `aria-invalid`/`aria-describedby` (from A5). | A5 follow-up / B0 |

## 6. Checks

- `LeaveSelectorStateTest`: 5 passed.
- Full suite on PHP 8.5.11: **609 tests, 605 passed, 4 failed**. They are the same 4 that A5 recorded as already failing on its base: ContractAutoRenewalWorkflow, ContractCreatePerformance, ContractPayClause (PDF), ContractRenewal. No new failure.
- No JS bundle change (Blade/Alpine only), so `npm run build` is not needed.

## 7. Still required before "done"

1. QA + technical lead review of the diff and of the evidence above.
2. ~~A pass on a slow connection~~. Done (§4, "Slow connection"). The delay was simulated in the page, not on a real slow network.
3. Push, PR, merge.

## 8. Handoff to A3

- The selector is now reliable for both locales. `form.leave_balance_id` reaches the server as a string id or `null`, never as placeholder text. Callers that compare it to an int should cast it (as `AllLeaveRequestModule::edit` does).
- Fix the stale `form.type` on a type change (§5) together with A3's impact display.
