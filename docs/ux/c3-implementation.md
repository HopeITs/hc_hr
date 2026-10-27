# C3 · Work inbox and the leave decision rules

Task: C3 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-09) · Date: 2026-10-27 · Branch `hr-ux-by-rafi`
Inputs: C3-D [approval-source-contract.md](approval-source-contract.md) (DEC-05 + revision, C3D-Q1…Q6); C1 menu; B6 notifications; A3 pricing snapshot.
Status: **built and tested; review pending. Needs `php artisan migrate` (one additive migration).**

## 1. Release note for HR (what changes for people)

| Who | Before | After C3 |
|---|---|---|
| **Direct manager** | Decided from *All leaves* | Same, plus everything waiting appears in **صندوق المهام** with a count |
| **HR (city)** | Could accept or reject any request in its city | **Only the direct manager decides.** HR decides **only** when the manager is on an accepted leave today («بالنيابة عن …»), or when the employee **has no direct manager** (C3-Q1). HR still sees every request and is notified. |
| **HR after a decision** | "Seen by HR" from the table | "Seen by HR" with an **optional note**, or **Object** with a **required note**: the request goes back to the manager as pending; if it was accepted, its days return to the balance at once (C3D-Q5, Q6). The manager and the employee are notified. |
| **Technical manager** | Could see and decide | **No longer counts** (DEC-05 revision). |
| **Employee** | — | Sees HR's notes in the request details, and why a request is pending again |
| **Checklist deliverers/receivers** | Separate pages, one by one | Also in the inbox; **several items can be confirmed at once** (C3D-Q4). The old pages still work. |

## 2. What was built

| Part | Where | Notes |
|---|---|---|
| Who may decide | [`LeaveDecisionAuthority`](../../app/Services/LeaveDecisionAuthority.php), `LeaveRequestPolicy::decide` | Bases: `manager` (holds the position the requester's position reports to, contract running today), `on_behalf` (HR in scope, every such manager on an accepted leave covering now: whole days for a day leave, the hours for an hourly one), `no_manager` (HR in scope, no direct manager or nobody holds that position). The role must hold `leaves.decide`. |
| Recorded with the decision | `leave_requests.decision_basis`, `decided_on_behalf_of` | Set inside `LeaveRequestForm::submitDecision()`'s transaction. The next-step text says «اتخذ … (الموارد البشرية) القرار بالنيابة عن …» or «… لا يوجد مدير مباشر». |
| Technical manager removed | `LeaveRequest::scopeManageableBy`, `LeaveNotificationService::managerRecipients` | Column kept; it grants nothing. |
| HR review / objection | [`LeaveRequestReview`](../../app/Services/LeaveRequestReview.php) (`markSeen`, `object`), `LeaveRequestPolicy::object` | One writer for `hr_approved`; the table's row action now calls it too. Lock → authorize → write → notify after commit. Objection gives back `deductionHours()` (the A3 snapshot) under the request-then-balance lock order. |
| Notes | `leave_request_notes` (`LeaveRequestNote`), `partials/hr-notes` | Shown in the details on *All leaves* and *My leaves* (only the requester, the manager and HR may open them). |
| Objection notification | `LeaveRequestObjected` | Manager: "back with you to decide", opens the inbox row. Employee: "pending again" with HR's note. |
| Inbox rows | [`WorkInbox`](../../app/Services/WorkInbox.php) | `leave:{id}:decide`, `leave:{id}:review`, `checklist:{id}:deliver`, `checklist:{id}:receive`, `checklist:{id}:finalize`. Each from the source's own scoped query, kept only if its policy allows the action now. No leave reason, pay or national id in a row. Former staff never listed. |
| Inbox page | `/inbox` ([`Inbox\Index`](../../app/Livewire/Inbox/Index.php)) | Filters (all, leave decisions, to review, items) with counts; per row: who, what, dates, duration, waiting time, "starts soon", on-behalf/no-manager line, details link; actions inline in real `<form>`s, so **Enter submits** (G-B-7). `?open=leave:12:decide` highlights a row (objection notification). |
| Outcomes | `Inbox\Index` | §4.1: `done` (toast, row leaves), `already_handled` ("already rejected by … on …", G-B-1's text), `changed` (the request was edited since the inbox loaded: nothing written, decide again), `not_allowed` (same message whether missing or out of scope), `invalid` (error on the row's own field, entries kept). |
| Batch | `previewBatch` → confirm dialog → `applyBatch` | Checklist confirmations only (C3D-Q4), at most 50. Preview writes nothing; each item runs `ChecklistWorkflow` in its own transaction; per-outcome summary; items not confirmed stay selected. Audit entries carry a shared `batch_id`. |
| Menu | `Navigation::inbox()` | Route `inbox`; shown when the role has a source; badge = the page's row count; refreshes after each action (`inbox-changed`). |
| Old screens | *All leaves*, checklist pages | Kept. A decision link HR may no longer use now opens the details instead of a 403. |

## 3. Data change and recovery

Migration `2026_10_27_000001_add_decision_basis_and_notes_to_leave_requests`: two nullable columns on `leave_requests` and a new `leave_request_notes` table. Additive only (no `change()`, so SQLite does not rebuild the table); existing rows untouched; decisions made before C3 have a null basis and read as before. `down()` drops the columns and the table; decisions are not affected.

## 4. Tests

| AC | Tests |
|---|---|
| **AC-C3-01** counts and rows agree with actionable work | `test_c3_inbox_rows_are_the_actionable_work_of_each_role`; `WorkInboxChecklistTest::test_rows_and_count_are_the_items_waiting_on_the_user` (incl. no page permission → no row); `test_c1_work_inbox_counts_pending_requests_of_the_managers_team`; `NavigationAreasTest` (inbox page per role, every link opens) |
| **AC-C3-02** no double deduction or double transfer | `test_c3_deciding_from_the_inbox_uses_the_domain_decision_and_cannot_deduct_twice`; `test_a_decided_leave_cannot_be_decided_again_or_deducted_twice` (existing); `test_c3_an_objection_…` (a second objection gives nothing back twice); `WorkInboxChecklistTest::test_a_repeated_confirmation_is_already_handled` (one audit entry) |
| **AC-C3-03** rejections, confirmations, batches, notifications keep their semantics | `test_c3_a_rejection_without_a_reason_is_invalid_on_its_row`; `test_c3_hr_reviews_and_objects_from_the_inbox`; `test_c3_an_objection_needs_a_note_returns_the_days_and_goes_back_to_the_manager` (notifications to manager and employee); `test_c3_seen_by_hr_keeps_an_optional_note_and_is_one_way`; `WorkInboxChecklistTest::test_a_batch_confirms_each_item_by_its_owner_and_reports_per_item` (preview writes nothing, per-item results, shared `batch_id`, an item confirmed elsewhere is reported, not repeated) |
| Decision rules | `test_c3_the_direct_manager_decides_…`, `test_c3_hr_cannot_decide_while_the_direct_manager_is_at_work`, `test_c3_hr_decides_on_behalf_…`, `test_c3_hr_decides_when_there_is_no_direct_manager`, `test_c3_the_technical_manager_neither_sees_nor_decides`, `test_c3_a_manager_cannot_object`, `test_c3_a_request_changed_since_it_was_opened_is_not_decided` |

**Full suite:** 974 tests, 5479 assertions, 0 failures (final code, 2026-10-27).

Existing tests updated for the new rule: the decision fixtures' HR users and two managers now hold `leaves.decide` (the old `decide` ignored the permission); their requesters have no direct manager, so HR decides by C3-Q1. No test was loosened.

## 5. Browser checks (synthetic copy `hc-hr-gb12`, migrated; Arabic)

| Who | Check | Result |
|---|---|---|
| `manager.alp` | Inbox | 1 row, «نبيل صليبا · طلب إجازة بانتظار قرارك · سنوية · 06-11-2026 – 07-11-2026 · يومان»; badge 1 |
| `manager.alp` | «رفض», empty reason, Enter | Submits on Enter; one error «حقل سبب الرفض مطلوب.» under the field |
| `manager.alp` | Reason typed, Enter | «رُفض طلب الإجازة.»; empty state; badge recounted |
| `hr.alp` | Inbox | 15: 6 decisions (all «لا يوجد مدير مباشر…», C3-Q1), 6 to review (incl. request 36 just rejected), 3 items; request 36 not offered to decide |
| `hr.alp` | Note + Enter on «راجعته الموارد البشرية» | «سُجّل أن الموارد البشرية راجعته.»; badge 15 → 14 |
| `hr.alp` | Details of request 36 | «ملاحظات الموارد البشرية · ملاحظة · لارا منصور · … · تمت مراجعة القرار» |

**Fixed on the way:** an old validation error stayed after the field was corrected (error bag now reset per action); the `invalid` message showed twice (now only under the field); «بانتظارك منذ منذ …» (waiting time now a plain length); the menu badge did not recount after an action.

**Second browser pass (2026-10-27, `hr.alp`):**

| Check | Result |
|---|---|
| Batch: two checklist items ticked | Button «تأكيد عنصرين»; preview «تأكيد هذه العناصر؟ سيُؤكَّد عنصران.» (nothing written); after confirming, both confirmed, one item left, badge 14 → 12. Both audit entries carry the same `batch_id`. |
| Objection on request 65 (accepted, `used` = 24) | «أُعيد الطلب إلى المدير.»; request pending, decider cleared; `used` 24 → 0; note saved as `objection` by `HR-ALP`; notifications to both holders of the manager position and to the employee. |
| On behalf: both managers of request 65 put on an accepted leave today (two synthetic rows, removed afterwards) | HR's inbox offers request 65 with «تقرر بالنيابة عن جورج خوري، هادي شامي»; accepted → `decision_basis` = `on_behalf`, `decided_on_behalf_of` = 34, `used` back to 24; the employee reads «اتخذ لارا منصور (الموارد البشرية) القرار بالنيابة عن جورج خوري بتاريخ 27-10-2026.» |
| Phone 375×667 | No sideways scroll; every button ≥ 44 px; cards stack. |

Fixed after this pass: the Arabic batch summary («أُكّد 2» → «أُكّد عنصران», full plural forms) and the on-behalf line («… لأنه في إجازة» did not fit two managers → «(في إجازة)»).

**Limits seen:** when a manager position has several holders, the record names the first one only (`decided_on_behalf_of` is one id). **Not checked in the browser:** English (G-B-12).

## 6. Limits and handoff

- **Scale:** HR's decide rows are filtered per row through `LeaveDecisionAuthority` (a few queries each). Fine for a city's pending requests; if a city ever holds hundreds, add a query-level filter. Each source lists at most 100 rows; counts above that would be capped.
- **Not in the inbox (C3-D §2.2):** overtime, salary advances and contract amendments do not exist yet; no provider was faked.
- **Still on the technical manager:** the leave-balance page for managers and `LeaveBalanceForm`'s balance notification still read `tech_manager_id`; they are about balances, not decisions, and are left for a follow-up.
- **New-request notifications** still open *All leaves* (the old screen, kept). Only the objection notification opens the inbox row.
- **C4** reads `WorkInbox::count()` for the «لك الآن» section.
