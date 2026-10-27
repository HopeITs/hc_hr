# G-B: Core task completion and data recovery gate

Task G-B in [ux-prompts-v3.md](ux-prompts-v3.md) (R-02, R-03, R-04, R-05, R-13, R-16, R-17, R-20).
Integrated SHA: **`ccb3575b`** (`origin/main`: B1, B2, B3, B4, B5, B6, M1 on top of G-A). The working tree differed only by `docs/ux/approval-source-contract.md` (C3-D, documentation). Date: 2026-10-23.
Prerequisites: B1, B2, B3, B4, B5, B6, M1, all on `main`. No product code, schema or data was changed by G-B.

**Gate result: PASS WITH CONDITIONS.** All four core tasks completed with the expected permissions in English and Arabic, on desktop and phone. No input was lost, no request, contract or account was duplicated, and no accounting or permission boundary was broken. The conditions are the open items in §5; none is waived silently.

**Independence:** this gate was run by the developer who implemented B2 and B5. The QA review (§7) should treat those areas with that in mind.

## 1. Full suite (AC-G-B-03)

`vendor/bin/phpunit` on PHP 8.5.11, SQLite `:memory:`, at the SHA above.

| Run | Tests | Assertions | Failures | Errors |
|---|---|---|---|---|
| G-A (`8141fdc7`) | 759 | 4304 | 0 | 0 |
| **G-B (`ccb3575b`)** | **930** | **5125** | **0** | **0** |

## 2. Environment

- **Data:** a fresh synthetic SQLite copy built from the SHA (X1 builder + X2 session states), `C:/laragon/tmp/hc-hr-gb`, with a clean snapshot; launch entry "hc-hr G-B (synthetic SQLite copy)", port 8400.
- **Accounts:** synthetic only (`*@example.test`). No `.env` change; the operational database was not touched.
- **Browser:** the built-in Chromium pane.
  - Desktop runs at the pane's size, 1238×742.
  - Phone runs emulated at 375×667. At 375×812 the emulated page is scaled to fit the pane and clicks miss, so 667 was used.
- **Two independent sessions** were obtained with two host names (`127.0.0.1` and `localhost`), which keep separate cookies.
- **Input:** real clicks and key presses, with three exceptions:
  - date fields that already had a value were set directly, because key presses went into the wrong segment;
  - sign-in was set up by script; it is not part of any task;
  - the role switch was invoked through the switcher's own action (§4).
- **This is a moderator walkthrough, not participant sessions:** no times, SEQ or SUS are reported. The X2 tasks' outcome codes and end-state checks are used as the pass criteria.

## 3. The four X2 tasks (AC-G-B-01)

End states were checked in the session database against [x2/task-scripts.md](x2/task-scripts.md) v1.1.

| Task | Run | Persona | Outcome | End-state evidence |
|---|---|---|---|---|
| A · Request leave | EN, desktop | staff ALP | **S** | Request 70: annual, day, 31 Oct, reason, pending. **Double-clicking the confirmation's Save made one request.** |
| A · Request leave | AR, phone | staff ALP | **S** | Request 71 (3 Nov). Saving without a reason was refused («حقل السبب مطلوب.») and the type and dates were kept. |
| B · Find an employee | EN, desktop | HR ALP | **S** | "Mira Najjar" → one row, ICDL Trainer, `synth.040@example.test` (= manifest); profile shows the same email. |
| B · Find an employee | AR, phone | HR HMS | **S** | «كريم عيسى» → one card, «مدبرة مركز 95», `synth.194@example.test` (= manifest); search chip and Clear all; no page overflow. |
| C · Review a request | AR, phone | manager ALP | **S** | Request 64 rejected by the manager (employee 34), reason stored, balance `used` unchanged. Rejecting without a reason was refused. |
| C · Review a request | EN, desktop | manager HMS | **S** | Request 67 rejected by the manager (195) with the exact reason; the other pending requests untouched; done by keyboard (§4). |
| D · Create a contract | EN, desktop | HR ALP | **S** | Contract 317: position 2, Volunteer Contract, full time, on site, 6 Nov – 31 Dec, Sun–Thu 09:00–17:00, fixed per month; one seat; one account created (B5). |
| D · Create a contract | AR, desktop | HR HMS | **S** | Contract 318, same checks on position 53; one seat; one account. |
| D′ · Reservation upgrade | AR, phone | HR ALP | **S** | The composer found reservation 315 and filled the position (banner shown); **315 was upgraded in place** (same id, now full time, from 8 Nov); seats 2/2; total contracts 318 (316 + 2 new); one account. |

**Coverage:** every task in both languages and on both device classes. Not every language × device × task combination was run (9 runs including D′, of 16 combinations). Not run: A AR desktop, A EN phone, B AR desktop, B EN phone, C AR desktop, C EN phone, D EN phone.

**Permissions observed:**
- HR saw the B5 Incomplete / Awaiting contract views.
- The manager's pending list held only their reports' requests.
- The HMS HR role (two cities) chose the city through the employee.
- The employee role (after the switch) lost *All Leaves*.
- The leave dialogs of a staff persona's own requests needed no other permission.
- Pay visibility and the staff persona's full menu were **not** re-checked here; they rely on the suite (`SalaryVisibilityTest`, `DirectoryAccessPolicyTest`, A2 tests). A *Termination Reasons* link on the staff page is by design (G-B-13).

## 4. Recovery and integrity checks (AC-G-B-02)

| Check | How | Result |
|---|---|---|
| Duplicate submit, leave | Double-click on the confirmation's Save (Task A EN) | **Pass**: 1 request |
| Duplicate submit + **server failure**, contract | Composer complete → **server stopped** → Create → server restarted → **double-click** Create (Task D EN) | **Pass on data**: every entry kept while the server was down; after restart, 1 contract, 1 seat, 1 account, no other contract changed. **Defect G-B-2** (no feedback while down). |
| Reservation not duplicated | D′ | **Pass**: upgraded in place |
| **Two sessions, same request** | HR (session 2) opened the decision for request 64; the manager (session 1) rejected it; HR then chose Accept and saved | **Pass on data**: still rejected by the manager, balance unchanged. **Defect G-B-1** (misleading message). |
| **Role switch on a stale page** | Synthetic manager given a second role (`employee`) in the session copy; tab A kept a decision open for request 65; tab B switched the active role; tab A then saved Accept | **Pass**: refused («محظور»), request 65 still pending with no approver; tab B's menu lost *All Leaves*. Role restored afterwards. |
| Validation keeps input | Leave without reason (AR phone); rejection without reason (AR phone) | **Pass** on data; **Defect G-B-3** (phone: the message is off-screen) |
| Table state | Employees: search → profile → Back | **Pass**: the search is in the URL (`?globalSearch=…`) and the box, and the result is kept |
| Unsaved changes, leave dialog | Cancel/Escape on an untouched dialog | **Pass**: closed without a question (B2/A5-M) |
| Keyboard | Task A fields (select2, dates, reason) by keys; confirmation by Tab/Enter; Task C by Tab/Enter | **Pass with defect G-B-7** (Enter in the decision's reason field does not submit) |
| Phone layout | All phone runs | **Pass**: no page-level horizontal scroll (`scrollWidth = innerWidth`); M1 sticky action bars present. Leave tables scroll sideways *inside* their container (G-B-4). |

Data integrity summary: no duplicate request, contract, account or decision; balances changed only by accepted leave (none in this run); no write from a stale session or a lost role.

## 5. Open items and disposition (AC-G-B-03)

| # | Item | Severity | Disposition | Owner |
|---|---|---|---|---|
| G-B-1 | A stale leave decision (another person already decided) shows the full-page "Forbidden — you don't have permission" error instead of "already rejected by …". Data is safe. | Medium | **Fixed** (§9). Opening or saving a decision on a request that is no longer pending shows its details and "already rejected by … on …", the `already_handled` outcome of [approval-source-contract.md](approval-source-contract.md) §4.1. C3's inbox still maps its own refusals the same way. | Rafi |
| G-B-2 | When the server cannot be reached, *Create contract* (and Livewire actions generally) give **no feedback**: no toast, no error, the button returns to normal. Entries are kept and a retry is safe (B2 token). | Medium | **Fixed** (§9): one error toast with *Try again*, which repeats the failed actions ([feedback-contract.md](feedback-contract.md) §2.3). | Rafi |
| G-B-3 | Phone: after a failed save in a long dialog, the inline error can be below the visible area; focus is not moved and the summary stays hidden (it counts an off-screen message as "shown"). | Medium | **Fixed** (§9): after a failed save the first error is scrolled into view and focused; an inline message counts as "shown" only when it is inside the visible area. | Rafi |
| G-B-4 | Leave tables (All leaves, My leaves, Balances) are sideways tables on phones; the status column is off-screen. Already listed as not done in B4 and M1. | Medium | **Fixed** (§9): the three leave tables are cards on phones (B4-D §6). | Rafi (for B4/M1) |
| G-B-5 | Tapping a decided leave row opens the **editor**, not a read-only view (B4D-Q2 was applied to employees and contracts only). | Low | **Fixed** (§9): a decided row or card opens the read-only details (B4D-Q2); Edit is its own button. | Rafi (for B4) |
| G-B-6 | The leave **edit** dialog opened by the manager showed the *Employee* field empty (red border). Seen once, on request of Nour Halabi (2 Oct). Not investigated further here. | **Medium** (was Low, to confirm) | **Fixed** (§9). Reproduced, and worse than it looked: a manager's edit was validated against the manager's own contract, so every save was refused, and a leave type from the manager's own balance was accepted. | Rafi |
| G-B-7 | Keyboard: pressing Enter in the decision dialog's reason field does nothing (no `<form>`); Tab to *Save* works. | Low | **Fixed** (§9): the C3 inbox uses real forms, and the *All leaves* decision dialog is now a form too; Enter submits in both. | Rafi |
| G-B-8 | Composer: choosing the contract type after entering the start date resets the start date to the type's default. | Low | **Fixed** (§9): a date the user typed is kept when the type changes. | Rafi |
| G-B-9 | HR (city) can still decide leave requests; DEC-05 revision says only the direct manager decides (A3 §4, C3-D G1). | Policy gap, known | **Done in C3** ([c3-implementation.md](c3-implementation.md)): only the direct manager decides; HR decides on their behalf when they are on leave today, or when there is no direct manager (C3-Q1). | C3 |
| G-B-10 | Auto-renew is on by default and the composer does not ask (X2 note). | Info | **Answered (2026-10-27, G-B10-Q1 = 2):** keep it on by default, but show it as a ticked box on the create screen so HR sees it and can untick it before saving (trials stay excluded). Still editable afterwards from the edit screen and the contracts list. **Built** (§9). | HR product owner |
| G-B-11 | Not covered here: screen reader, real phone and on-screen keyboard, 200 % zoom; 7 of 16 task × language × device combinations; participant timings. | Limitation | Carried to X2-R / G-C; G-A item 2 still open. | QA lead |
| G-B-12 | Test environment only: `/en/...` redirects to https on the `php -S` copy; viewport emulation taller than the pane misplaces clicks; one-step typing does not fill date inputs. | Info | No product action. | — |

| G-B-13 | A *Termination Reasons* link (`/termination-reasons`) appeared in a staff persona's page. **Checked: by design.** The route requires `project-basics.view`, and `config/access-control.php` grants that read permission to the `employee` and `manager` baselines. Writes on those reference tables are a separate matter (G-A item 4). | Info | **Answered (2026-10-27, G-B13-Q1 = 2) and fixed** (§9): the reference pages left out of a role's menu by C0-Q4 are refused by URL too. | HR product owner |

**Release-blocking items:** none. No item loses data, duplicates a write, or exposes data across roles or cities.

## 6. Limits of this gate

- Synthetic data, one browser engine, emulated phone, moderator (not participant) runs.
- Run by the B2/B5 implementer.
- The B5 onboarding screens were not re-walked here; they rely on B5's own browser evidence ([b5-implementation.md](b5-implementation.md) §4) and the suite.

## 7. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-G-B-01** The four tasks complete in EN/AR on desktop and phone with expected permissions. | **Met, with coverage limits** | §3: 9 successful runs; each task in both languages and both device classes; 7 combinations not run (§3, G-B-11) |
| **AC-G-B-02** Recovery tests preserve input and accounting; requests/accounts are not duplicated. | **Met** | §4: double-submit (leave, contract), server failure + retry, two sessions, role switch, validation; database checks after each |
| **AC-G-B-03** The milestone result cites one integrated SHA and measurable evidence. | **Met** | `ccb3575b`; §1 suite 930/930; §3–§4 record ids and database states |

## 8. Handoff

C0 and C3-D may proceed. C3 takes G-B-7, G-B-9; G-B-6 is fixed, so C3 may reuse the leave edit dialog. G-B-1 to G-B-6 and G-B-8 are fixed (§9); nothing from G-B is left for G-C except the C3 items and the HR/QA notes. **Formal review (QA engineer + technical lead): pending.**

## 9. Follow-up: G-B-1 and G-B-2 fixed

Date: 2026-10-23. On `hr-ux-by-rafi` after `8872f852`. Checked on a separate synthetic copy (`C:/laragon/tmp/hc-hr-gb12`, launch entry "hc-hr G-B-1/2 fixes", port 8401), so the gate's own database above is unchanged.

**G-B-1: already decided.**
- **Change:** `LeaveRequestForm::submitDecision()` checks before validation, and again under the row lock, whether the request is still pending. If it is not, and the actor may still see it, it raises `LeaveDecisionAlreadyMade`, so nothing is written and no notification is sent. `AllLeaveRequestModule` then closes the decision, opens the request's details and shows an info toast from `LeaveRequestNextStep::alreadyDecided()`. `openDecision()` does the same for a row or link that is out of date. Anyone who may no longer see the request still gets the policy's 403, which says nothing about it.
- **The same rule covers a repeat of your own decision** (double click, or *Try again* after a lost response). It says "Your decision to accept this request was already saved on …" instead of Forbidden.
- **Browser, AR, two sessions:**
  1. HR ALP (`localhost`) opened the decision for request 66.
  2. The manager ALP (`127.0.0.1`) rejected it with a reason.
  3. HR then chose Accept and saved.
  4. HR saw the details dialog (status «مرفوض», the manager's reason) and the toast «سبق أن رُفض هذا الطلب من قِبل جورج خوري بتاريخ 23-10-2026, 16:49. لم يُحفظ قرارك.»
  5. The database still showed rejected by employee 34, balance `used` 0, and no new notification.
- **Tests** (`LeaveRequestWorkflowTest`): someone else decided first; a stale decision is reported before validation; opening an already-decided decision shows its details; out-of-scope access is still forbidden with no toast; a repeat of your own decision. The last one replaces the old test that expected 403.

**G-B-2: lost connection.**
- **Change:** `feedback.js` listens for Livewire action failures (fetch threw) instead of the request hook. A failure the user caused shows one error toast with *Try again* («إعادة المحاولة»), which sends the same actions again. The toast store gained a browser-only `action` button next to *Undo*. Details: [feedback-contract.md](feedback-contract.md) §2.3.
- **Browser, AR, HR ALP, request 65.** The connection was cut inside the page (Livewire's `fetch` rejected, as when the network drops), because stopping the server would also have ended the browser session.
  1. Accept, then Save twice while offline: **one** toast with *Try again*. The choice was kept. Both `submitDecision` calls and a background `updateCount` poll failed; the poll was not reported.
  2. Back online, *Try again*: exactly **one** `submitDecision` was sent, followed by the success toast and the dialog closing.
  3. Database: request 65 accepted once by employee 1, balance 62 `used` 0 → 24 h (3 days × 8 h), one new notification.
- **Phone 375 px:** the toast is 343 px wide, with no sideways page scroll.
- **Not covered:** *Try again* is not covered by an automated test (the suite has no JS runner). A real network drop on a phone has not been tried.

**G-B-3: fixed (2026-10-26).**
- **Change** (`resources/js/feedback.js`):
  - The error summary counts an inline message as already shown only if it is **inside the visible area**: the window and every scrolling container around it, such as a dialog's body on a phone (`isInView`). This is re-checked after a save, a click or a key press, not on scroll, so the list does not jump while the user scrolls.
  - After a failed save the user caused, the first error's field is scrolled to the middle and focused; if the field is on another tab or in a closed section, the summary is focused instead. This was started in `8872f852`.
  - The scroll is now **instant**: the smooth scroll did not move the dialog's scrolling container at all, so the field stayed below the screen.
- **Browser** (copy `hc-hr-gb12`, staff ALP, Arabic, leave request, reason left empty and scrolled out of view, Submit pressed with Enter):

  | Size | Reason field before | After the failed save |
  |---|---|---|
  | Phone 375×667, first failure | top 682 px (off screen) | focus on the reason, top 516 px (on screen), error visible |
  | Phone 375×667, second failure | top 682 px | same; one focus move |
  | Desktop 1200×700 | top 823 px (off screen) | focus on the reason, error visible, summary hidden (nothing duplicated) |

  An earlier phone run, before the instant scroll, showed the summary correctly listing the off-screen error but left the field below the screen.
- **Tests:** none added; the suite has no JS runner. Full suite: 934 tests, 1 failure in `ContractsTableB4Test::test_no_amount_in_the_rendered_html_or_payload_under_any_column_choice`, which passes 3 of 3 on its own. It is a flaky check (it looks for the digits `8731` in HTML that also holds random component ids and checksums), not related to this change; to be raised with the B4 owner.

**G-B-6: fixed (2026-10-26).**
- **Cause:** the leave form chose "whose leave is this" from the signed-in user. Only HR may pick an employee, so for anyone else it used the editor's own profile, even when a manager was editing a team member's request (`LeaveRequestPolicy::update()` allows it).
  - The *Employee* dropdown is filled only for HR, so a manager saw it empty.
  - The leave type was validated against the **manager's** contract. Every save of a team member's real leave type was refused ("The selected Leave Type is invalid").
  - A leave type from the manager's **own** balance was accepted, so an edit could move the request onto the manager's balance.
- **Change:**
  - `LeaveRequestForm::targetEmployee()`: an edit by someone who cannot pick employees is about the request's own employee. HR keeps its city-scoped choice; a new request by staff still uses their own profile.
  - The leave dialog (`all.blade.php`): when there is no employee list and the dialog is an edit, it shows the employee's name read-only instead of an empty dropdown.
- **Tests** (`LeaveRequestWorkflowTest`), both failing without the change:
  - `test_gb6_a_manager_editing_a_team_members_request_sees_the_employee_and_can_save`;
  - `test_gb6_a_manager_cannot_move_a_team_members_request_onto_their_own_balance`.
- **Browser** (copy `hc-hr-gb12`, `manager.alp`, Arabic, phone width):
  - Edit on request 64 shows «نور حلبي», the employee of the original report, and no empty field. The red border seen earlier is the leave type dropdown's focus style, not an error.
  - Changing the reason and saving went through the confirmation. Request 64 kept employee 7, balance 44 and its rejected status; only the reason changed.
- **Still open (G-B-5):** a manager reaches this editor only from a decided row; a pending row offers *Decision* and *Details*. Whether a decided request should be editable at all is G-B-5.

**G-B-8: fixed (2026-10-26).**
- **Change** (`resources/js/contract-composer.js`, `_assignment.blade.php`):
  - The composer remembers whether the user typed each date. Only the user's own input in a date field sets this; a type change or a seed does not.
  - Choosing a type keeps a typed date and fills only the dates that are still defaults.
  - With a typed start, the end follows the rules of `ContractForm::updatedContractTypeId()`:
    - trial: three months, without overflow, the same as Carbon's `addMonthsNoOverflow` (30 Nov → 28 Feb);
    - other types: the type's end if it is still after the start, otherwise one year less a day.
- **Server:** unchanged. The composer's dates already override the type defaults on save (`applyComposerPayload`).
- **Browser** (copy `hc-hr-gb12`, `hr.alp`, Arabic, contract create; dates entered by key presses):

  | Steps | Start | End |
  |---|---|---|
  | No date typed → *Volunteer Contract* | 1 Nov (default) | 31 Dec (default) |
  | Start 6 Nov typed → *Volunteer Contract* (before: reset to 1 Nov) | **6 Nov** | 31 Dec |
  | → *Trial Volunteer Contract* | **6 Nov** | 6 Feb 2027 (3 months) |
  | End 15 Dec typed → *Volunteer Contract* | **6 Nov** | **15 Dec** |
- **Tests:** none added (no JS runner).

**Suite after G-B-6 and G-B-8:** 936 tests, 5163 assertions, 0 failures (the `ContractsTableB4Test` check that failed once above passed this time).

**G-B-4 and G-B-5: fixed (2026-10-26).** Built from the B4-D decisions already recorded (B4D-Q2, B4D-Q7, [table-contract.md](table-contract.md) §3 and §6); no new question.
- **Phone cards** through the shared `cardView()` hook that the employee and contract lists already use. Below `md` the table is replaced by cards, so nothing scrolls sideways.

  | Table | Card |
  |---|---|
  | All leaves | **Employee name** · leave type · dates · duration · status · *Seen by HR* (HR only, decided requests) · the row's actions |
  | My leaves | **Leave type** · dates · duration · status, with *Seen by HR* on the same line · View, plus Edit and Delete while pending |
  | Leave balances | **Name** · years of service · one row each for deserved, used and remaining · Edit |

- **G-B-5:** in All leaves a decided row, or a tap on its card, opens the **read-only details**, not the editor. Pending still opens the decision.
  - Edit is a separate button after View, shown only with `leaves.update`.
  - Each table builds its action buttons once (`leaveActions()`), for the table column and the card alike.
- **Status colour (found on the way):** the status pill's classes were built at runtime (`bg-{color}-100`), so Tailwind never generated them and the pill had no colour, in the table too.
  - Both tables and the cards now use one partial with literal classes (`leave/partials/card-status`): pending amber, accepted green, rejected red text on grey.
  - The look matches the employee status.
- **Tests** (`LeaveRequestWorkflowTest`), both failing on the old tables:
  - `test_gb45_all_leaves_cards_and_a_decided_request_opens_the_read_only_details`;
  - `test_gb4_my_leaves_cards_show_the_request_and_its_actions`.
- **Browser** (copy `hc-hr-gb12`, Arabic):

  | Check | Size | Result |
  |---|---|---|
  | `manager.alp` · All leaves | 375×667 | 11 cards; no page scroll sideways; the date range reads start → end (checked by position); tapping accepted request 65 opened *Leave details*, not the editor |
  | `hr.alp` · Balances | 375×667 | 15 cards; figures readable on their own rows |
  | `staff.alp` · My leaves | 375×667 | 6 cards; pending with View/Edit/Delete; accepted with "Seen by HR" |
  | `staff.alp` · My leaves | 1200×700 | the table shows (coloured pills) and the cards are hidden |

- **Security:** the card helpers (`leaveActions`, `rowOpenAction`, `showsHrReview`, `cardBalance`) are `protected`, so the browser cannot call them. Only `cardView`, which just names a Blade view, is public; it is listed in `livewire_exempt`, as on the employee and contract tables. `LivewireMutationCoverageTest` caught the first, public version.
- **Not done here (B4D-Q4):** the default tab per role (managers on "Needs my decision", HR on "Not yet seen by HR") and the tab kept in the URL. These belong with C3's inbox.

**Suite after G-B-4/5:** 938 tests, 5174 assertions, 0 failures. In one earlier run the flaky `8731` check in `ContractsTableB4Test` failed again, this time in `test_phone_cards_render_with_the_named_actions_and_no_pay`; it passed on the rerun.

**G-B-7 and G-B-9: closed (2026-10-27).**

- **G-B-9** was built in C3 (DEC-05 revision): see [c3-implementation.md](c3-implementation.md) §1–§2 and its `test_c3_*` tests.
- **G-B-7:** C3's inbox already submitted on Enter. The decision dialog on *All leaves* still had no `<form>`, so Enter in the reject-reason field did nothing there. The dialog body is now `<form wire:submit.prevent="submitDecision">` and *Save* is its submit button. The old `wire:click` was removed, because with both a click on *Save* would send the decision twice.
- **Test:** `LeaveRequestWorkflowTest::test_the_decision_dialog_is_a_form_so_enter_submits_it`.
- **Browser** (copy `hc-hr-gb12`, `hr.alp`, Arabic, request 30 with no direct manager):
  - «رفض» chosen, empty reason, Enter: one error «حقل سبب الرفض مطلوب.», nothing saved.
  - Reason typed, Enter: dialog closed with «تم إتمام العملية بنجاح». The request was rejected once with basis `no_manager`.

**G-B-10: answered (2026-10-27).**

- **Today:** a new contract gets auto-renew switched on (`ContractObserver::creating`) without HR seeing it. The create screen does not show the toggle. Trials are always off.
- **Changing it now:** only HR in the contract's city (`manageAutoRenew`) can change it, from the edit screen (termination section) or the contracts list.
- **Decision (G-B10-Q1 = answer 2):** default stays **on**, but the create screen shows it as a ticked box that HR can untick before saving. Trials never show it. Editing afterwards is unchanged.
- **Built (2026-10-27):**
  - **Where it shows:** on the create screen, under the dates, already on. The same partial `composer/_auto-renew.blade.php` is used in the edit screen's termination section.
  - **Hidden:** when the trial type is chosen.
  - **Who sees and saves it:** HR with `contracts.create` (`ContractCreate::canSetAutoRenew`, the same rule as `ContractForm::canPersistAutoRenew`). The contract's city is already held to the role's by the identity validation. For a renewal, `manageAutoRenew` on the predecessor applies.
  - **Other roles:** they never see it, and the contract gets `ContractObserver`'s default.
  - **Safety:** an unset value is never written as off (`auto_renew !== null`), so a draft upgrade or another caller keeps the default.
- **Tests:** in `ContractAutoRenewalWorkflowTest`:
  - `test_hr_sets_auto_renew_while_creating_a_contract`: off and on are saved as chosen; a trial with the switch on still saves off.
  - `test_a_role_other_than_hr_does_not_see_auto_renew_while_creating`.
- **Suite:** 978 tests, 5504 assertions, 0 failures.
- **Browser** (`hr.alp`, Arabic): box shown ticked under the dates; hidden after choosing «عقد تطوّع تجريبي»; shown again for «عقد تطوّع».

**G-B-13: answered and fixed (2026-10-27).**

- **Decision (G-B13-Q1 = answer 2):** an employee and a manager may no longer open the reference pages their menu leaves out (C0-Q4 answer 2). They get 403 instead.
  - **`employee`:** centers, programs, positions, and the nine project-basics pages (including Termination Reasons).
  - **`manager`:** positions and project basics. A manager keeps centers and programs for the team.
  - **Everyone:** the org chart and the colleague drawer stay open.
- **How:**
  - One list, `config('access-control.reference_pages_hidden_from')`, is read through `User::canOpenReferencePage()`.
  - It feeds both the *Organisation* menu (`Navigation`) and a new `reference_page:{group}` route middleware, which replaces `active_permission:{group}.view` on those routes.
  - The "View positions" links on the centers table and the center page follow the same check.
- **Why the permissions were not revoked:** `positions.view` also opens the org chart and the colleague drawer, which every role keeps. No migration and no change to role data. To undo, empty the list.
- **Test:** `NavigationAreasTest::test_reference_pages_left_out_of_the_menu_are_refused_by_url` (403 for each hidden page; the org chart, and centers and programs for a manager, still 200; `positions.view` still held).
