# C3-D · Approval-source and decision contract (proposed)

Status: **complete; product-owner answers recorded 2026-10-23 (§9); G-B passed with conditions ([g-b-gate.md](g-b-gate.md)); awaiting formal review.** Not marked done until reviewed.
Date: 2026-10-23. Task: C3-D in [ux-prompts-v3.md](ux-prompts-v3.md) (R-09). Owner role: backend lead + HR product owner. Reviewer: HR product owner + technical lead.
Consumers: **C3** (unified approvals inbox), **D4** (onboarding/renewal/leaving journeys).

This is a design document. **No application code, schema or data was changed.** Every key, class, column and screen name below is **PROPOSED** until C3 builds it.

The rule this document enforces: **the inbox lists and launches; each domain decides.** The inbox never writes a leave status, a balance or a checklist state itself. It calls the one method that already owns that decision.

---

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision inspected | `hr-ux-by-rafi` @ `ccb3575b` (B5 on top of `main` with B1–B4, B6, M1, B2) |
| A2-D | [access-policy.md](access-policy.md): roles, city scope, deny behaviour |
| A3-D / A3 | [leave-calculation-contract.md](leave-calculation-contract.md), [a3-implementation.md](a3-implementation.md): pricing snapshot, balance effect, next-step text |
| **G-B** | **PASS WITH CONDITIONS** at `ccb3575b` ([g-b-gate.md](g-b-gate.md)). It changed no source below. Four of its items land in C3 (§10a); G-B-1 has since been fixed on the existing page. |
| DEC-05 (2026-10-16) + revision for leave (2026-10-20) | [decisions.md](decisions.md), quoted in §2.3 |
| Related | C5-D [team-coverage-contract.md](team-coverage-contract.md) (shares the "my reports" rule); B6 [b6-implementation.md](b6-implementation.md) (notification `subject` and request state) |

## 2. Inventory of real sources (task step 1)

### 2.1 Sources that exist in this checkout

| # | Source | What the actor does | Domain owner (the only writer) | Today's UI |
|---|---|---|---|---|
| S1 | **Leave request: decision** | Direct manager (today also HR) accepts or rejects a pending request | `LeaveRequestForm::submitDecision()` | *Leaves → All*, decision dialog (`AllLeaveRequestModule::openDecision/submitDecision`) |
| S2 | **Leave request: HR review** | HR marks a decided request "Seen by HR" (`hr_approved`) | `AllLeaveRequestTable::markAsReviewed()` (should move behind a domain method, §4) | *Leaves → All*, row action |
| S3 | **Checklist: delivery confirmation** | The assignee confirms they handed an item over | `ChecklistWorkflow::markDelivered()` | *Checklist → Pending items* |
| S4 | **Checklist: return confirmation** | The nominated receiver confirms an item came back | `ChecklistWorkflow::markReceived()` | *Checklist → Pending returns* |
| S5 | **Checklist: close a return cycle** | HR closes a completed return (removes the assignment) | `ChecklistWorkflow::finalizeReturn()` | Employee edit → Checklist tab |

S3–S5 are **confirmations, not approvals**: there is nothing to reject. They belong in a "my pending actions" inbox because they wait on one named person, and they already have the safest write path in the app (scoped lookup + expected state + row lock + audit log).

### 2.2 Sources that do **not** exist (no fake providers, AC-C3-D-03)

| Named by | Source | State in this checkout | Treatment |
|---|---|---|---|
| DEC-05 | Overtime | No model, migration, route or UI (`grep` for overtime/advance/amendment finds nothing) | Not in the inbox. A provider is added only when the module exists. |
| DEC-05 | Salary advances | Same | Same; when built, **one by one, never bulk** (DEC-05) |
| DEC-05 | Contract amendments | Same (contract *edits* exist, but are direct HR edits, not requests) | Same; when built, **one by one** (DEC-05) |
| Task text | Recruitment | Only on a separate branch (`origin/rms-main`), not in this checkout | Not a source here |

### 2.3 Decisions in force

DEC-05 (2026-10-16): inbox sources are all leave types, overtime, salary advances and contract amendments. The rejection reason is mandatory, visible to the volunteer and kept in the history. Batch approval applies only to simple requests (e.g. short leave within balance); advances and amendments are approved one by one.

DEC-05 revision (2026-10-20, leave only):
- the volunteer submits, and the direct manager and HR are notified together;
- **only the direct manager decides**; HR does not approve;
- the technical manager no longer exists;
- HR marks "Seen by HR" and may **object**, which sends the request back to the manager as pending, with HR's note;
- HR decides **on behalf of** the manager only while the manager is on accepted leave, and this is shown as "on behalf of <manager>";
- HR may add an optional note, visible to HR, the manager and the volunteer.

### 2.4 Where today's code differs from the decisions (C3 must close these)

| # | Decision | Code today | Evidence |
|---|---|---|---|
| G1 | Only the direct manager decides | `scopeManageableBy` gives HR (city), HR-SYR and super-admin the same `decide` ability as the manager | `LeaveRequest::scopeManageableBy`, `LeaveRequestPolicy::decide`; recorded in A3 §4 |
| G2 | Technical manager abolished | `tech_manager_id` still grants manage/decide | `scopeManageableBy` (`orWhereIn('tech_manager_id', …)`); C5-D T-4 |
| G3 | HR may object (back to pending, with a note) | No objection action, no note column | no `hr_note`/objection in code or migrations |
| G4 | HR decides on behalf only while the manager is on accepted leave, labelled | HR can always decide; no "on behalf" record | — |
| G5 | Optional HR note visible to HR, manager and volunteer | Not stored | — |

These are **already decided** (DEC-05 revision): C3 implements them; this document only fixes how (§4.3). No policy is changed here.

## 3. Shared projection contract (task step 2, AC-C3-D-01)

Every inbox row is built by a **source provider** from the domain's own scoped query. A provider never adds rows its source's policy would not show.

### 3.1 Stable key

`{source}:{record id}:{action}`, e.g. `leave:812:decide`, `leave:812:review`, `checklist:44:deliver`, `checklist:44:receive`, `checklist:44:finalize`.

It matches B6's notification `subject {type, id, action}`, so a notification and an inbox row point to the same thing and share the same "awaiting / handled / unavailable" state (`NotificationRequestState`).

### 3.2 Row fields (what the list may show)

| Field | Content | Privacy rule |
|---|---|---|
| `key` | §3.1 | — |
| `source`, `action` | e.g. leave / decide | — |
| `subject` | The employee the item is about (name, photo) | Only rows the actor's scope allows (§5) |
| `summary` | Leave: type, dates, duration. Checklist: item name. | **Never** the leave `reason` text (B6 §4), never pay, never national ID |
| `city` | Governorate | Shown only to roles that span cities (B4-D rule) |
| `waiting_since` | Request/assignment time | — |
| `urgency` | Leave: start date is soon or already passed. Checklist: none. | — |
| `state` | `awaiting` / `handled` / `unavailable` (B6 vocabulary) | — |
| `version` | Opaque token from the record (`updated_at` + status), §4.1 | — |
| `capabilities` | What this actor may do *now*: `decide`, `review`, `object`, `confirm`, `finalize`, `bulk` | Computed from the domain policy, never from menu visibility |
| `bulk_class` | `none` / `eligible` (§6; today only checklist confirmations) | — |
| `detail` | Where the full record opens (existing drawer/dialog) | The detail view re-authorizes |

### 3.3 Per-source contract

| | S1 Leave decide | S2 Leave review | S3 Deliver | S4 Receive | S5 Finalize |
|---|---|---|---|---|---|
| **Authorized scope (list)** | `LeaveRequest::manageableBy($user)` + `status = pending`; after C3: direct manager's reports, or HR when acting on behalf (G1, G4) | `manageableBy` + decided + `hr_approved = false`, HR roles and super-admin (`LeaveRequestPolicy::review`) | `Checklist` where `delivered_id = me` and `delivered_status = pending` | `receiver_id = me` and `returned_status = pending` | Employees HR may update (`EmployeePolicy::update`) with `returned_status = received` |
| **State / version** | `status` + `updated_at` | `hr_approved` + `updated_at` | `delivered_status` | `returned_status` | `returned_status` |
| **Decision owner** | `LeaveRequestForm::submitDecision()` | domain method wrapping `markAsReviewed` (§4) | `ChecklistWorkflow::markDelivered()` | `ChecklistWorkflow::markReceived()` | `ChecklistWorkflow::finalizeReturn()` |
| **Effects (same transaction)** | Accept: status, `approved_by/at`, balance `used += computed_hours` (snapshot, A3), under request then balance locks. Reject: status, reason. | `hr_approved = true` | state + time + notes | state + time + notes | delete the cycle record |
| **Notifications (owner)** | `LeaveNotificationService::sendDecision()` after commit | `sendHrReview()` | none today | none today | none |
| **History (owner)** | Activity log on `LeaveRequest` (LogsActivity) + `approved_by/at` | activity log | `ChecklistWorkflow::record()` | same | same |
| **Required input** | Reject: reason (required, ≤ 255, existing) | — (objection: note, §9 Q5) | optional note | optional note | — |

The inbox calls exactly these owners. It does not copy validation, pricing, balance arithmetic or notification code (A3 hand-off: "do not reimplement date arithmetic").

## 4. Conflicts, staleness, idempotency (task step 3, AC-C3-D-02)

### 4.1 One decision path, five outcomes

Every action from the inbox carries `key` + `version` and returns exactly one of:

| Outcome | When | What the user sees |
|---|---|---|
| `done` | The owner method succeeded | Row leaves the list; B0 toast |
| `already_handled` | The record is no longer in the expected state (someone else decided, reviewed or confirmed it) | "Already {accepted/rejected/seen/confirmed} by {name} at {time}." Not an error; the row refreshes |
| `changed` | Same state, but `version` differs (e.g. the requester edited dates while the dialog was open) | "This request changed since you opened it." The new values are shown and nothing is written until confirmed again |
| `not_allowed` | Policy now denies (role switched, city scope changed, manager changed) | Neutral message; no details of the record (A2-D deny behaviour, B6 `unavailable`) |
| `invalid` | Domain validation failed (e.g. the balance no longer covers the leave, reason missing) | Field-linked error (B2), entries kept |

### 4.2 How each owner guarantees it

- **Lock, then authorize, then check state:** S1 already does this (`lockForUpdate`, then `Gate::authorize('decide')`, whose `status = pending` check turns a second decision into a refusal). C3 maps that refusal to `already_handled` by re-reading the row, instead of a bare 403.
- **Expected state in the query:** S3–S5 (`ChecklistWorkflow::transition`) already make a repeat a no-match. C3 maps no-match to `already_handled` when the record still exists and the actor could see it, and to `not_allowed` otherwise.
- **S2** today authorizes after a lock, but lives in a table component. C3 moves it behind a domain method (`LeaveRequestReview::markSeen`, PROPOSED) so the inbox and the table share one writer.
- **Idempotency:** a repeated identical request (double click, retry after a lost response) returns `already_handled`, which is safe to retry. Notifications are sent only by the request that changed the state. B6's ledger pattern (`notification_deliveries`) applies if a notification is ever sent outside that request.
- **Version check (`changed`):** new. Leave can be edited by the requester while pending, so the decider must not accept dates they did not see. Checklist rows have no editable content and skip it.

### 4.3 Closing the DEC-05 revision gaps (G1–G5)

| Gap | Contract for C3 |
|---|---|
| G1, G4 | `decide` is granted to the requester's **direct manager** (active contract, `direct_manager_id`, the C5-D rule). HR gets `decide` **only** while that manager has an accepted leave covering today; the decision stores `decided_on_behalf_of` (PROPOSED column) and the response shows "on behalf of <manager>". |
| G2 | `tech_manager_id` is removed from `manageableBy` and `decide` (shared with C5-D T-4). The column stays (no data change in C3-D). |
| G3, G5 | New `object` capability for HR on an accepted or rejected request: status back to **pending**, HR note **required** (Q5), and if it was accepted the deducted days return to the balance **in the same transaction** (Q6; the A3 snapshot is the amount to reverse), so the request only reserves them again as pending (DEC-03); manager re-notified. The note is stored (PROPOSED `leave_request_notes`: author, role, text, kind = note/objection, time) and visible to HR, the manager and the requester. |

Changing who may decide changes existing behaviour (HR deciding today). It is **decided** (DEC-05 revision), but C3 must ship it with a release note for HR and tests for each role.

### 4.4 Atomicity

- A balance change and a status change happen in **one transaction**, under the existing lock order (request, then balance). Objection reversal uses the same order.
- Checklist: one row per transition, as today.
- Notifications and toasts come **after** commit, never inside the transaction.

## 5. Authorization (AC-C3-D-02)

- The inbox has **no permission of its own** that widens anything. A row appears only if the source's own scope query returns it, and every action re-runs that source's policy inside its lock.
- **Counts** (badges) use the same scope queries. No unscoped `count()`.
- **Active role and city:** the inbox follows the active role (`myRole()`), like every list. A role switch re-queries; actions from a stale page return `not_allowed` (§4.1).
- **Privacy:** rows never carry the leave reason, pay, national ID or documents (§3.2). The detail view is the existing one, with its own checks. Former staff and drafts never appear (A2-D Q3, B5).
- **General manager:** today `manageableBy` gives the general manager their own reports, like a manager. Unchanged unless HR says otherwise.

## 6. Bulk actions (task step 4, AC-C3-D-03)

### 6.1 Which sources may use bulk (decided, §9)

| Source / action | Bulk | Why |
|---|---|---|
| S1 accept | **No** (Q1) | Every leave decision is made one by one. DEC-05 *allowed* bulk for simple requests; HR chose not to use it. |
| S1 reject | **No** (Q2) | Each rejection needs its own reason, visible to that volunteer (DEC-05) |
| S2 "Seen by HR" | **No** (Q3) | One by one, as today |
| S2 object | No | Needs its own required note (Q5) |
| S3 / S4 confirmations (deliver, receive) | **Yes** (Q4) | The only bulk action. Each item is still confirmed by its owner method; a bulk confirmation carries no per-item note (an item that needs a note is confirmed on its own). |
| S5 finalize | No | Rare; one by one |
| Overtime, advances, amendments | **No** (DEC-05), when they exist | — |

### 6.2 How a batch works

1. **Select**: only rows with `bulk_class = eligible` and the `bulk` capability can be ticked: today, checklist delivery and return confirmations (§6.1). At most **50** per batch (PROPOSED).
2. **Preview**: the list of what will happen, re-computed on the server ("8 items: 6 to confirm as delivered, 2 already confirmed by someone else and removed"). **Preview writes nothing.**
3. **Confirm**: the user confirms the previewed set (B0 confirm dialog).
4. **Apply**: the server runs the **owner method once per item**, each in its own transaction with its own lock and policy check. There is **no all-or-nothing**: one item failing never undoes another.
5. **Result**: per item, one of the §4.1 outcomes, with a summary ("10 accepted, 1 already handled by Rami, 1 no longer within balance"). Items that failed stay in the list, selected, so they can be handled one by one.

**Audit:** each item is logged by its owner as usual (`ChecklistWorkflow::record()`), plus a `batch_id` (PROPOSED) in the activity-log properties, so a batch can be traced. A batch is not a record that can be undone. The same mechanism serves any future source HR makes bulk-eligible, without changing the owners.

## 7. What C3 must not do

- Write any leave, balance or checklist column directly (only via the §3.3 owners).
- Re-implement pricing, balance checks, notification recipients or audit.
- Add a provider for a source that does not exist (§2.2).
- Show more in a row than §3.2 allows, or count rows with an unscoped query.

## 8. Evidence used

Source trace at `ccb3575b`: `LeaveRequestForm::submitDecision`, `LeaveRequestPolicy`, `LeaveRequest::scopeManageableBy`, `AllLeaveRequestModule`, `AllLeaveRequestTable::markAsReviewed`, `LeaveNotificationService`, `ChecklistWorkflow`, `PendingItems`/`PendingReturns`, `NotificationRequestState` (B6), A3 and C5-D notes. **No application test is claimed from this review.**

## 9. Decisions (product owner, 2026-10-23)

Answered by the developer acting for the HR product owner (Rafi), question by question in Arabic. Q1 was first left open for HR, then answered; Q1 and Q3 were changed after the first answer. The final answers are recorded.

| ID | Question | Today | Decision | Was it the recommendation? |
|---|---|---|---|---|
| **C3D-Q1** | May a manager accept several leave requests at once, and which? | One by one | **No bulk: every leave request is decided on its own** | No (recommended annual ≤ 3 days within balance) |
| **C3D-Q2** | May several requests be rejected together with one shared reason? | One by one, reason required | **No: each rejection with its own reason** | Yes |
| **C3D-Q3** | May HR mark several requests "Seen by HR" at once? | One by one | **No: one by one** | No (recommended yes) |
| **C3D-Q4** | Do checklist delivery/return confirmations appear in the unified inbox, and may several be confirmed at once? | Separate pages, one by one | **In the inbox, and may be confirmed in bulk** | No (recommended one by one) |
| **C3D-Q5** | Must HR write a note when objecting? | No objection exists | **Yes, required** | Yes |
| **C3D-Q6** | When HR objects to an accepted leave, do the days return to the balance immediately? | No objection exists | **Yes, in the same step** | Yes |

**Consequences for C3:** the only bulk action is checklist confirmation (§6.1). Leave decisions, HR reviews and objections are one by one, so the batch mechanism (§6.2) is built once for checklist and reused if a future source is made eligible.

## 10. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-C3-D-01** Every supported source has one authoritative decision owner and a shared projection contract. | **Met (proposed)** | §2.1 five sources; §3.3 one owner each; §3.1–3.2 shared key and row fields |
| **AC-C3-D-02** Authorization, conflict/idempotency and batch semantics are agreed for each source. | **Met (decided 2026-10-23)** | §4 outcomes and per-owner guarantees; §4.3 objection (Q5, Q6); §5 authorization; §6.2 batch semantics; §9 answers |
| **AC-C3-D-03** Bulk capability is explicit; no fake recruitment provider is added. | **Met (decided)** | §6.1 per-source bulk table (only checklist confirmations); §2.2 non-existent sources excluded |

**G-B: passed with conditions. HR decisions: recorded (§9). Formal review: pending.** C3-D is **not** marked done.

## 10a. Items from G-B for C3

| G-B item | What C3 must do |
|---|---|
| G-B-1 | **Fixed on the existing page** ([g-b-gate.md](g-b-gate.md) §9): `LeaveRequestForm::submitDecision()` raises `LeaveDecisionAlreadyMade` (no write, no notification) and the page shows the details with "already rejected by … on …" (`LeaveRequestNextStep::alreadyDecided()`). C3: reuse both for the inbox's `already_handled`, do not rebuild them. |
| G-B-7 | Enter in the decision's reason field does nothing (no `<form>`). The inbox decision UI must submit on Enter like other forms. |
| G-B-9 | HR can still decide (G1 above). Confirmed in the browser on the synthetic copy. |
| G-B-6 | The leave **edit** dialog opened by a manager showed an empty Employee field. Reproduce before the inbox reuses that dialog for "View/Edit". |
