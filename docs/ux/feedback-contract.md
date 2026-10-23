# B0 · Shared feedback: verified API contract

Status: **implemented and verified, awaiting review** (QA engineer + technical lead). Not marked done until reviewed.
Date: 2026-10-21. Task: B0 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-03, R-05, R-19).
Consumers: A3, A6, A7, A5-M, G-A, B2, B3, B6, C2, D3.

B0 builds nothing new. The feedback system merged in `5feb02aa` (and adjusted by A5 in `d8effd8c`) is **the** way to report outcomes and ask before acting. This document records its real API, the rules every consumer follows, the evidence for B0's acceptance criteria, and the one defect fixed.

---

## 1. Starting state

| Item | Value |
|---|---|
| Revision | `hr-ux-by-rafi` @ `3af56fae` (= `main` `6b3df356` + B5-D) |
| Prerequisite A5 | Implemented and committed on `main` (`d8effd8c`), awaiting review ([a5-implementation.md](a5-implementation.md)) |
| P0 / A1 | Approved 2026-10-20 |
| Test target | X2 session copy ([x2/test-environment.md](x2/test-environment.md)): synthetic SQLite, `*@example.test` personas, rebuilt on this revision, assets rebuilt (`npm run build`) |

## 2. The API (real names)

### 2.1 Server side: `App\Livewire\Concerns\WithFeedback`

Both methods are **protected**: they cannot be called from the browser (test `test_the_browser_cannot_raise_toasts_or_confirmations_itself`).

| Method | Signature | What it does |
|---|---|---|
| `toast()` | `toast(?string $message = null, string $type = 'success', ?string $undo = null, mixed $undoPayload = null)` | Queues a toast. `$type` is `success`, `error` or `info` (anything else throws). With no message it shows `general.success-message` (EN "Operation completed successfully"). `$undo` names a **public** method on the same component, called with `$undoPayload`. |
| `confirm()` | `confirm(string $then, mixed $payload = null, ?string $title = null, ?string $message = null, ?string $confirmLabel = null, bool $danger = true, ?string $html = null)` | Opens the shared dialog; on **confirm** the browser calls `$then` on this component (with `$payload` as its single argument, or none). Defaults are the delete wording (`general.delete-item`, `general.confirm-delete-item`, `general.delete`). `danger: false` for non-destructive questions. `$html` only from a Blade view (it is inserted as HTML). |

Delivery (`App\Livewire\Features\SupportToasts`, registered in `AppServiceProvider` while booting):
- **No redirect:** each toast becomes the browser event `toast`.
- **Redirect** (normal or `navigate: true`): toasts are flashed to the session key `toasts` (`SupportToasts::SESSION_KEY`) **without their undo** and rendered once by the next page.
- `session('status')` strings also become a success toast, except the `verification-link-sent` code.

### 2.2 Browser side: `resources/js/feedback.js`

| Name | Kind | Use |
|---|---|---|
| `toast` | window event, detail `{type, message, undo, duration}` | Sent by `toast()`. Also usable from markup: `$dispatch('toast', {type: 'info', message: '…'})` (only for messages that need no server outcome). |
| `confirm-dialog` | window event, detail `{componentId, method, payload, title, message, html, confirmLabel, danger}` | Sent by `confirm()`. |
| `$store.toasts` | Alpine store | `push(opts)`, `dismiss(id)`, `pause(id)`, `resume(id)`, `undo(id)`. At most **4** visible; durations: success/info **5 s**, error **8 s**, with Undo **8 s**; paused while hovered or focused. |
| `$store.confirm` | Alpine store | `ask(options) → Promise<boolean>`, `settle(bool)`. A second `ask` answers the first one "no". |
| `$confirm(options)` | Alpine magic | From markup: `x-on:click="if (await $confirm()) $wire.deleteRole()"`. |

Components, mounted **once** in `components/layouts/app.blade.php`: `<x-toasts/>` (live region `role="status"`, `aria-live="polite"`, present before any toast) and `<x-confirm-dialog/>` (`role="alertdialog"`, `aria-modal="true"`, labelled and described, focus trap, initial focus on **Cancel**).

Translation keys: `general.toast.{success,error,info,undo,dismiss}` (screen-reader prefixes "Success:", "Error:", "Note:"; AR «تم بنجاح:», «خطأ:», «ملاحظة:», «تراجع», «إغلاق الإشعار»), `general.delete-item`, `general.confirm-delete-item`, `general.delete`, `general.cancel`.

## 3. Rules for consumers (task step 3)

1. **The confirmation is UI, not authorization.** `$then` is a public Livewire action: anyone can call it without the dialog. It **must** be classified in `config/access-control.php` (`livewire` with a permission) **and** check record/city scope itself (`Gate::authorize`, a scoped query). Exempting a confirm target is allowed only when the method authorizes inside its body.
2. **One domain write per confirmed action.** The dialog calls `$then` once per confirmation (verified, §5). Duplicate protection for slow networks (B2, X1-I) still belongs on the server.
3. **Validate before asking.** A consumer that builds a confirmation from form data validates first, as `MyLeaveRequestModule::confirmSave()` does. Otherwise the dialog would summarise invalid input.
4. **Toast after the outcome, never before.** Raise `toast()` after the write succeeded. For failures, show the field-linked error (inline and in the error summary) and, only when nothing inline explains it, `toast(…, type: 'error')`.
5. **Do not build a second system.** No component-local toasts, `alert()`, SweetAlert or extra live regions.
6. **`$html`** only from Blade (escaped). Never put raw user input in it.

## 4. Undo: what is supported (task step 4)

- **Supported:** an `undo` callback **on the same component instance, on the same page**. It is dropped when the request redirects (the instance no longer exists), and it disappears with the toast (8 s, paused while focused or hovered).
- **The undo method is public**, so it follows rule 1: classified, re-authorized, and **not trusting its payload** (the payload comes back from the browser and can be edited).
- **It must respect later edits:** undo restores only what the user just removed, never overwrites data changed since.
- **In use today:** exactly one consumer, `Dashboard\EmployeeNotesCard::delete()` → `restoreNote($snapshot)`. Verified safe: it writes only to the signed-in user's own notes (`ownerId()` from the session), whitelists `type` and casts the rest. Calling it directly can only create a note for oneself, which `createNote` already allows.
- **Not supported, on purpose:** undo of HR records, contracts, leave decisions, approvals or anything with notifications, balances or audit effects. Those need a real reversal workflow (B6/C3), not a toast button.

## 5. Evidence (browser checks on the X2 session copy, 2026-10-21)

Desktop 1440×900 and phone 375×812, Chromium (Claude desktop built-in browser), EN and AR. Keyboard checks used real key events.

| Check | Result |
|---|---|
| Live region present before any toast | `role="status"`, `aria-live="polite"`, exactly 1 region |
| Toast text for screen readers | Prefixed: "Success:", AR «خطأ:» for an error toast |
| Listener registration after 6 `wire:navigate` hops (dashboard ↔ My Leaves) | 1 `toast` event → **1** toast; 1 region; 1 dialog |
| Server confirm → save → toast after those hops (`MyLeaveRequestModule::confirmSave` → `processSave`) | Dialog "Confirmation" / "Save", non-destructive; `processSave` called **once**; **1** leave request created (4 → 5); **1** success toast |
| Toast raised before a `navigate: true` redirect (`EmployeeCreate::createDraftAndContinue`) | Shown **once** on `/employees/{id}/edit` |
| Same toast after more navigation and browser Back | **0** reappearances |
| Arabic (`/ar`, `dir="rtl"`) | Dialog «حذف العنصر» / «حذف» / «إلغاء»; dismiss «إغلاق الإشعار»; error prefix «خطأ:» |
| Language switch | Header EN/AR links are full page loads, so the dialog's default wording is re-read in the new language; no stale wording |
| Keyboard: Enter on a row's Delete (`EmployeeTable::confirmDelete`) | Dialog opens; focus on **Cancel**; `alertdialog`, `aria-modal`, labelled "Delete Item" |
| Keyboard: Tab / Tab / Shift+Tab inside the dialog | Delete → Cancel → Delete; focus never leaves the dialog |
| Keyboard: Escape | Dialog closes; nothing deleted; **focus back on the Delete button** in 5 of 5 repeat runs (an earlier run, right after a missed pointer click, lost focus to the page; not reproduced) |
| Keyboard: Enter on the default (Cancel) | Closes; nothing deleted; focus back on the trigger. A stray Enter never confirms. |
| Keyboard: Tab into a toast | From the last page control into the toast's Dismiss; the countdown **pauses** (5 s toast still shown after 6 s); Enter dismisses |
| Phone 375 px | Toast 343 px wide with 16 px gutters; no page sideways scroll |
| Failed action keeps input (leave form saved without a type) | Field error "The selected Leave Type is invalid."; no dialog; modal stays open; typed reason and date kept; no success toast |

### 5.1 Authorization audit of every `confirm()` target (AC-B0-03)

A read-only script listed all **32** `$this->confirm('method', …)` targets in `app/Livewire` and checked each against `config/access-control.php` and its own body:

- **30** are mapped to a permission (`ActiveLivewirePermissionMiddleware` enforces it on any direct call). **17** of these also authorize inside the method (`Gate::authorize`, `abort_unless`, a scoped query).
- **2** are exempt (`AllLeaveRequestModule::processSave`, `MyLeaveRequestModule::processSave`) and authorize inside the body (`authorizeActivePermission` + `Gate::authorize`).
- **0** unclassified; **0** rely on the dialog alone.
- **For A2 (outside B0):** the other **13** mapped targets rely **only** on the coarse permission with no record or city check in the body: the Basics reference-table `delete()` and `toggleItemStatusConfirmed()` (annual-leave rules, checklist items, cities, contract types, course categories, departments, job levels, leave types, termination reasons) and `Program\Detail`/`Program\Index` `delete()` / `toggleItemStatusConfirmed()`. That is a scope question for A2, not a B0 defect.

### 5.2 Tests

| Run | Result |
|---|---|
| `WithFeedbackTest`, `AccessibilityTest`, `EmployeeEditSaveFeedbackTest`, `LivewireMutationCoverageTest` | **31 passed** (85 assertions) |
| Full suite (`vendor/bin/phpunit`, PHP 8.5 + `pdo_sqlite`) | **604 tests, 599 passed, 5 failed**, all pre-existing and outside B0: A1 register #6, #11, #12, #13, and `ContractPdfRendererTest::test_a_remote_chrome_is_served_the_page_without_a_public_url` (new with `926b12f1`, fails identically **without** the B0 change; for A6) |

No new automated test was added: the defect fixed in §6 is a browser focus behaviour that a PHP test can only check as markup, which the plan rules out as evidence. The browser checks above are the evidence.

## 6. Defect found and fixed

**Two `x-init` attributes on the confirm dialog's root** (`resources/views/components/confirm-dialog.blade.php`, added by A5 `d8effd8c`). An HTML element keeps only the **first** of two identically named attributes, so the second one, the focus fallback that moves focus to Cancel when `x-trap` runs before the panel is painted, was **silently dropped**. Confirmed in the live DOM (one `x-init`, no `$watch`). A5's note "focus goes to Cancel even when the first paint is late" therefore relied on that dead code; the normal-paint focus it observed came from `x-trap`.

**Fix:** both statements now live in one `x-init` (defaults first, then the `$watch`), with a comment saying why. Verified live: one `x-init`, contains both; defaults still applied ("Delete Item"); no Blade comment leaked into the attribute; keyboard checks in §5 run on the fixed version.

## 7. Limitations and notes for consumers

- **Screen reader:** checked by the accessibility tree and live-region markup, **not** with a real screen reader (NVDA/VoiceOver). A real pass is still owed, as for A5.
- **Errors are announced politely**, by design (`toasts.blade.php`): the inline field errors carry the urgent message.
- **Toast reachability:** the toast region is last in the tab order, so a keyboard user reaches Undo only after the rest of the page. With 8 s (paused once focused) that is tight on long pages. **For A5-M/B2:** do not rely on Undo as the only recovery path for keyboard users.
- **After dismissing a toast** with the keyboard, focus falls to the page body. Acceptable for now; a later task may return it to the previously focused control.
- **Touch targets:** Undo (≈53×26 px) and Dismiss (32×32 px) are below the product's 44 px target (not the WCAG AA minimum). **For M1/D2.**
- **Locale prefixes and `wire:navigate`:** the app's own links carry the current locale's prefix (`/ar/contracts` in Arabic) and navigate normally. A scripted `wire:navigate` to the **other** locale's prefix is redirected by the locale middleware; the language switch itself is a full page load. *(Corrected 2026-10-21 during A7: an earlier version said the app's links are un-prefixed, which is true only of the English default.)*

## 8. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-B0-01** The feedback component/API exists on the integrated branch and is documented with real names. | **Met** | §2 (methods, signatures, events, stores, magic, keys, session key), on `3af56fae` |
| **AC-B0-02** Success is announced, confirmations are keyboard accessible, and navigation does not duplicate messages. | **Met** (real screen-reader pass pending, §7) | §5: live region + prefixes EN/AR; Enter / Tab / Shift+Tab / Escape / default-Cancel; focus return; 1 event → 1 toast after 6 hops; flashed toast once, not repeated after navigation or Back |
| **AC-B0-03** Failed actions preserve recoverable state, and confirmations cannot bypass authorization. | **Met** | §5 failed leave save keeps input; §5.1 all 32 targets server-protected; §4 undo re-checked |

**Formal review (QA engineer + technical lead): pending.** B0 is **not** marked done.

## 9. Handoff

| Consumer | Uses |
|---|---|
| A3, A6, B2, B6, C2, D3 | §2 API and §3 rules; outcome toasts after writes; field-linked errors first |
| A5-M, B2 | `$store.confirm` for the dirty-close question (one Escape path, confirm first, then the dialog, as A5 defined); §7 note on Undo for keyboard users |
| A7, B3 | Loading/empty states are **not** toasts: use local pending states; toasts only report outcomes |
| A2 | §5.1 list of confirm targets with permission-only checks |
| A6 | The PDF test failure noted in §5.2 |
| M1 / D2 | §7 touch-target sizes of toast buttons |
| G-A | §5 is the browser evidence for the feedback part of the gate |
