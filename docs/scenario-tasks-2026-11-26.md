# Tasks from the live scenario run (2026-11-26)

Each task is written as a prompt for a Claude Code session in this repo. Read CLAUDE.md first. Dependencies are listed per task. Evidence comes from the run on `hr_db`; see docs/scenario-run-report-2026-11-26-ar.md.

## T1 — Show "exceptional December leave" to the manager and to HR
**Priority:** high. **Depends on:** none.
A leave marked exceptional (December) is accepted by the manager alone, and neither the decision dialog (`mode=decision` on `leaves/all`) nor the HR inbox card (`Livewire/Inbox/Index.php`) says it is exceptional. The employee form says it "needs approval from your manager and HR".
Do: add a visible tag ("Exceptional December leave") to the decision dialog, the Team leaves row and the HR inbox card, and sort exceptional items first in the HR inbox. Confirm with the product owner whether HR objection after the fact (current behaviour) is the intended "approval"; if so, reword the employee-facing text, if not, add an HR gate. Cover with a Livewire test.

## T2 — Stale pending leave requests
**Priority:** high. **Depends on:** none.
15 pending requests have a start date in the past (e.g. leave 18–19 Aug 2026 still pending on 26 Nov). `leaves:send-decision-reminders` reminds once only.
Do: decide the rule (escalate to city HR after N days, and/or mark expired once the period has started), implement in the reminder command plus a visible "overdue" state, never touch balances silently. Test on the calendar like `LeaveChainSimulationTest`.

## T3 — HR inbox backlog of "Seen by HR"
**Priority:** medium. **Depends on:** none.
18 accepted leaves wait for "Seen by HR" for up to 5 months with no aging or reminder.
Do: show age, order oldest-first with the exceptional ones on top (see T1), add a weekly digest for city HR, and a "mark all seen" for items older than a threshold with a confirmation.

## T4 — Team leaves table usability
**Priority:** low. **Depends on:** none.
Row click opens the employee profile instead of the request; the Actions column is off-screen at 1280px; icon-only buttons have no accessible name; a duplicate "Save" button exists in the DOM.
Do: row click opens the request, keep Actions visible (sticky), add `aria-label`s, remove the duplicate dialog markup. Update `A4BilingualTest`/accessibility tests.

## T5 — Verify the alternative employee is saved
**Priority:** low. **Depends on:** none.
The "Alternative Employee" control could not be filled by browser automation and the stored request has `alternative_employee_id = NULL`.
Do: reproduce by hand and with a Livewire test (`LeaveAlternativeEmployeesTest`), fix if the selection is lost, and make the control reachable by keyboard/accessibility tree.

## T6 — Seat counters and over-capacity positions
**Priority:** high. **Depends on:** none.
On `hr_db`: positions 62 and 88 have `exist_emp` different from the running contracts (all their contracts were created in one minute by the 2026-06-10 import), and 62 ("Center keeper") has 6 holders for 3 seats, 88 has 4 for 1. (The first draft also listed 87, 135 and 154: wrong, measured by contract dates instead of the `is_active` flag the observer uses.)
Do: run `php artisan positions:recount-seats` (check its output first), find why the counter drifted (imports skip the check; deactivation paths?), add a weekly check that exits non-zero and logs, and decide with HR what to do with imported over-capacity.

## T7 — Tell HR when a seat handover needs a decision
**Priority:** medium. **Depends on:** T6.
30 positions have several running holders, so `SeatSuccession` hands nothing over ("HR decides") but nothing tells HR.
Do: a report or inbox item "Seats with several holders" for city HR, and a notification when someone leaves such a seat. Extend `SeatHandoverSimulationTest`.

## T8 — People, contracts and roles that disagree
**Priority:** high (security). **Depends on:** none.
On `hr_db`: 3 inactive employees still have an active role row; 3 contracts are flagged active but ended; 2 running contracts belong to inactive employees; 30 active employees have no running contract; 2 active positions have no direct manager.
Do: list each case, find the code path that leaves it (deactivation observer, import), fix, and add the checks to a nightly `--check` command that reports to HR Syria.

## T9 — Nightly role sync must leave one active role
**Priority:** medium. **Depends on:** none.
`OrgRoleSync::syncUser` gives a user with no roles `employee` with `is_active = 0` (tested on user 95). `keepOneRoleActive` runs only after a removal or suspension. 93 users have no role, 116 no active role, 9 active employees have no login.
Do: call `keepOneRoleActive` whenever the user ends with no active role, add a test in `OrgRoleSyncTest`, and decide how `EmployeeActivation` should create the 9 missing accounts.

## T10 — Demo data for recruitment and policies on hr_db
**Priority:** high (blocks the live runs). **Depends on:** none.
`hr_db` has no recruitment workflow definitions, no hiring campaigns and no policy documents, so scenarios 1, 4 and 13 cannot be walked.
Do: a seeder (not run in production) that adds the approval workflow definitions per scope, one campaign, and two policy documents (one `policy`, one `review`) marked required for onboarding. Document how to run it on `hr_db`.

## T11 — Sign-in lands HR Syria as manager
**Priority:** low. **Depends on:** none.
By design (`login_role_order`), HR Syria enters as manager and sees an empty inbox until switching role.
Do: show a one-line hint on the empty inbox ("You have HR work under the role HR · Syria — switch role") when another role of the user has pending items.

## T12 — Console script errors and the outdated user guide
**Priority:** low. **Depends on:** none.
"An unknown error occurred when fetching the script" appeared 3 times when opening the leave dialog. `docs/دليل-الاستخدام.md` (June) lacks attendance, recruitment, board, policies, journeys, amendments.
Do: find the failing script requests (network tab), then update the guide module by module.

## R3 — Run the board-meeting stories live (14)
**Depends on:** none. Chair, secretary, member and non-member; open/move/cancel, absence, minutes, follow-ups, archive upload, reminders, .ics, PDF.

## R4 — Run the policy stories live (13)
**Depends on:** T10. Upload PDF/Word/text, read and search, required-for-onboarding, agree, replace and re-agree, rename/delete, refused roles.

## R1 — Run scenarios 1, 4, 13 live (hiring → onboarding → policies)
**Depends on:** T10. Walk the chain on `hr_db` with real accounts, write findings into a report like docs/scenario-run-report-2026-11-26-ar.md, no video.

## R2 — Run scenarios 3, 5, 6, 7, 10, 23 live (contract, exit, seat handover, renewal, profile, digital ID)
**Depends on:** T6, T8. Same method and report.
