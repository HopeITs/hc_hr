# B6: Duplicate notifications and request progress

Task B6 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-20). Prerequisites: G-A ([g-a-gate.md](g-a-gate.md)), B0.
Baseline: `2375ac4`. Status: **implemented, not reviewed, browser checks pending.**

## 1. What was found

`SendContractExpirationWarnings` compared the day's existing notifications with a read-then-send check
(`created_at >= today()` plus an identical contract-id set). Two failures follow:
overlapping workers both pass the check before either sends; and a contract that lands in a different
group on a later run (a sibling contract added or ended) is warned again. Multiple roles do not
duplicate: the recipient is one user. Grouping is by manager and days-left, which is intended.

## 2. Policy (implemented)

- One warning per **contract + recipient + day**. The day is the date in `config('app.timezone')`.
- `notification_deliveries.idempotency_key` is unique: `kind:recipient:contract:Y-m-d`.
  `NotificationDeliveryLedger::claim()` uses `insertOrIgnore`; only the claim winner sends that contract.
- The send failing releases an undelivered claim so the next run retries. A crash between claim and
  send keeps the claim (at most once; the next day is a new claim). Delivered rows are kept as history.
  The `notifications` table is never edited or deleted.
- Manager warnings carry **no url**: a direct manager has no `contracts.view` access, so a link would
  only lead to a 403 (the existing test asserts this). Each contract id stays in the payload.

## 3. Request progress

`NotificationRequestState` reads the current record. Leave notifications now carry
`subject: {type, id, action}` (`decide` for new requests, `review` for HR's decision copy).
State is `awaiting` (policy `decide`/`review` allows it now), `handled`, `unavailable`
(record deleted or `view` denied) or informational. "Unread" is unchanged and separate.
Both lists show a badge, the request status and, while pending, the next actor from `LeaveRequestNextStep`.
`unavailable` rows show a neutral message instead of the stored body (employee name, leave type), and
opening one marks it read and shows a translated toast instead of redirecting to a 403/404.
Notifications written before this change have no subject and behave as before.

## 4. Confidential reasons

No notification carries the employee's leave `reason` (test added). The **rejection reason** is still in
the employee's and the HR copy; `test_rejection_notification_includes_the_reason...` asserts that
deliberately. **Decision for the HR product owner:** keep it in the HR copy or drop it there.

## 5. Evidence

| AC | Evidence |
|---|---|
| AC-B6-01 | Tests: repeat run, competing claim, failed send then retry, several roles, next reminder day, timezone (22:30 UTC = next day in Istanbul). Four fail on the old command. Real concurrency: 4 OS processes x 200 contracts on file SQLite gave 200 wins and 200 rows |
| AC-B6-02 | `LeaveRequestWorkflowTest`: awaiting then handled after a decision (still unread), authorized open redirects |
| AC-B6-03 | Same file: deleted record and lost role give no redirect, a toast and a neutral row. Not covered: grouped row per-contract navigation (none exists, see section 2) |

Full suite on PHP 8.5.12, SQLite memory: **786 passed, 4395 assertions, 0 failed.**

## 6. Limits

- No EN/AR desktop or phone browser check, no screen reader pass. **Pending.**
- Reference-table actions from G-A item 4 are not touched.
- Production: run `php artisan migrate`; the scheduler is unchanged. Rollback: revert the commit and drop
  `notification_deliveries` (nothing else reads it).
