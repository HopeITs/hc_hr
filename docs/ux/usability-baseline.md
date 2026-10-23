# X2 · Usability baseline and success measures (proposed)

## Needs reviewer decision

Two task names in the plan can be read two ways. The scripts currently use the recommended reading of each. **They will not be changed until the product owner answers.** Both must be settled before the pilot, because a change after the first counted session breaks comparability.

| # | Question | Option 1 | Option 2 | Recommendation | Impact of the choice |
|---|---|---|---|---|---|
| RD-1 | Does **"create a contract draft"** mean a new contract in the composer, or the existing **Draft** contract type? | **New contract in the composer** (current Task D; Draft type is the optional D′) | **Complete a Draft-type reservation** (the "draft found" path; D′ would become the core task) | **Option 1.** The composer is the path every HR officer uses for every new contract. A Draft reservation exists only when the employee was created with one, so Option 2 tests a narrower, less frequent path. SHARED RULES also require keeping "Draft type" separate from other meanings of "draft". | Option 1: no script change. Option 2: swap D and D′ (scenario card, fixture state, end-state check 1 becomes "the reservation became a real contract, no second contract"), scripts v1.2. The session data already has both cases ([x2/test-environment.md](x2/test-environment.md)), so neither choice needs new data. |
| RD-2 | Does **"review a request"** mean **reject with a reason**, or **approve**? | **Reject with a reason** (current Task C) | **Approve** | **Option 1.** Rejection goes through the required-reason path, the most likely place for validation errors and confusion with *Accept* / *Seen by HR*, and its end state cannot be reached by accident. An approval is one click on the same modal, so it shows less about the decision flow. | Option 1: no change. Option 2: new scenario card and end-state check (status Approved, no reason needed); C-E2 changes meaning; scripts v1.2. A possible middle way, if wanted: keep rejection as core and add an approval variant C′ recorded separately (also v1.2). |

These were routine assumptions X2-AS-1 and X2-AS-2 (§8); they are raised here because they decide what is measured.

Status: **instruments delivered; baseline observations PENDING USER RESEARCH; awaiting review** (QA engineer + technical lead). Not marked done until reviewed.
Date: 2026-10-19. Task: X2 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-15). Owner role: UX researcher + QA engineer.
Consumers: A5, A4, A7, X2-I. Later users of the same instrument: G-B, G-C, X2-R.

This document defines **how** the four highest-value tasks are measured with real users. It also records that **no user has been measured yet**. It contains no participant, time, success rate or SUS score. Anything that looks like a number of that kind is either a **proposed target** (§5) or an **arithmetic illustration** labelled as such.

| Deliverable | File |
|---|---|
| This summary, method, targets, limitations and handoff | `docs/ux/usability-baseline.md` |
| Task scripts for the four tasks | [x2/task-scripts.md](x2/task-scripts.md) |
| Anonymized observations: rules, consent, session sheet, empty record | [x2/observations.md](x2/observations.md), [x2/observations-template.csv](x2/observations-template.csv) |
| SUS and post-task ease method and scoring | [x2/sus-method.md](x2/sus-method.md) |
| Session test environment (synthetic data, how to build and reset it) | [x2/test-environment.md](x2/test-environment.md), `tests/Usability/Baseline/` |
| Screener, quota, run order, facilitator guide, pilot checklist | [x2/recruitment-and-sessions.md](x2/recruitment-and-sessions.md) |
| Review checklist (QA engineer + technical lead) | [x2/review-checklist.md](x2/review-checklist.md) |
| Moderator walkthrough of the scripts on the session copy (instrument check, **not** data) | [x2/dry-run-2026-10-19.md](x2/dry-run-2026-10-19.md) |

---

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision the scripts were written against | `main` @ `6b96806d473342929c536e7842547ae57c40c400`, the same as `origin/main` and the checked-out `hr-ux-by-rafi`. Working tree was clean before X2. |
| App code in that revision | Since A1 (`fb01a226`), `main` gained `5726436f` (`docs/ux/decisions.md`) and the X1 commits. Those touch only `tests/Performance/Baseline/` and `docs/ux/` (X1 doc §9, AC-X1-03). So the UI being baselined is the UI of the reviewed main plus P0's reconciled commits. |
| **A1 prerequisite** | [test-baseline.md](test-baseline.md): delivered, all three ACs show evidence (544 tests / 531 pass / 13 registered failures). The harness changes it describes are present on HEAD (`phpunit.xml` `<server>`/`<env force>` pins; the unconditional guard in `tests/TestCase.php`). **Its status is "implemented, awaiting review"**: the QA engineer + technical lead sign-off is still missing. The same is true of P0's [start-state.md](start-state.md). |
| Decisions | [decisions.md](decisions.md) records DEC-01…DEC-10 as settled on 2026-10-16. X2 depends on none of them. DEC-10 (telemetry) matters only to X2-I. |
| UI changes already merged | `87e31bfa` (shorter loader), `8769e98a`/`dd62fa25` (wire:navigate), `5feb02aa` (toasts/confirm dialog). As the task says, this is a baseline **for the next delta** (A3/A4/A5/A7/B0 onwards), not a before-measurement of those commits. |

**Why X2 proceeded although A1 is unreviewed:** A1's outputs and AC evidence exist, and nothing in them contradicts X2. X2 changes no code and only consumes A1's baseline SHA and its "synthetic data only" target rule. X1 proceeded on the same basis. **Consequence:** X2 cannot be marked done before A1's review is complete. If A1's review changes the starting SHA, §1 of this document must be updated.

### 1.1 Starting points inspected

| Source | Finding used here |
|---|---|
| `docs/دليل-الاستخدام.md` (v1.0, June 2026, Arabic only) | Gives the intended paths (§5 employees, §6 contracts, §7 leaves). **Partly out of date against the code.** The guide lists contract types "fixed / open-ended / temporary" with salary and allowances on one form; the app has a three-section composer (assignment, schedule, pay) with annual/service/Draft types. The guide says to press "طلب إجازة جديدة"; the button is labelled *إضافة جديد* / *Add New*. There is no English guide. The scripts therefore follow the **source code**, and the guide gaps are handed to D3. |
| UX report `HC-HR-UX-Improvement-Plan-AR.pdf` (15 Oct 2026) | **Not available on this machine** (it is not in the repository or in `Downloads`). Its content reached X2 only through the v3 plan's R-15 and the four task names. Recorded as a limitation (§7). |
| "Supplied v2 plan" | `docs/ux/ux-prompts-v2.md` in the repository is **byte-identical to v3** (`cmp`; see P0 §6). The original v2 file (`Telegram Desktop`) is not on this machine. No v2-only content could be compared. |
| Source code for the four tasks | Routes `routes/web.php`, `routes/employees-management.php`. Components: `MyLeaveRequestModule`, `AllLeaveRequestModule`/`AllLeaveRequestTable`, `LeaveRequestPolicy`, `LeaveRequest::scopeManageableBy`, `EmployeeTable`, `ContractCreate` plus the composer views. Permissions: `config/access-control.php`. Salary visibility: `config/salary.php`, `User::canViewSalary()`. Labels: `resources/lang/{en,ar}/general.php`. |
| X1 (read only, not edited) | [performance-baseline.md](performance-baseline.md) and its synthetic fixture (`tests/Performance/Baseline/build-fixtures.php`) are the nearest existing disposable target. |

## 2. What is measured (the four tasks)

Full definitions are in [x2/task-scripts.md](x2/task-scripts.md). Summary:

| ID | Task | Participant roles (current permissions) | Starting screen | Stop point | Time cap |
|---|---|---|---|---|---|
| A | Request leave: one full day of annual leave on a given date | employee (also manager, HR) | Dashboard | success toast and modal closed, or "done" | 5 min |
| B | Find an employee: give the work email of a named person with a shared surname | HR, general manager (others have no `employees.view`) | Dashboard | email said aloud, or the target's profile opened | 2 min |
| C | Review a request: reject a specific pending request with a given reason | manager over the requester, or city HR | Dashboard | decision modal closed with toast, or "done" | 4 min |
| D | Create a contract in the composer for an employee with no contract | city HR (never `super-admin`, which is salary-blind) | Dashboard | new contract's details page shown, or "done" | 10 min |

Optional variants, recorded separately and never pooled: **B′** gives the name in the other script than the interface. **D′** uses an employee with a Draft-type reservation.

## 3. Outcome, error and abandonment definitions (AC-X2-01)

These are the same for all four scripts ([task-scripts §0.3–0.4](x2/task-scripts.md)). Each script adds its own **end-state check** and task-specific error codes.

| Measure | Observable definition |
|---|---|
| **Success (S)** | The stop point is reached **and** every end-state item for that task holds (checked by the moderator in the UI/DB after the attempt) **and** no H2/H3 help was given. |
| **Success with assistance (SA)** | Same end state, but with H2 (a hint naming a place or control) or H3 (a demonstration). Reported separately; it is not an unassisted success. |
| **Error** | A visible event another observer could mark: wrong screen (E-NAV), validation message shown (E-VAL), input lost (E-LOSS), duplicate write (E-DUP), blocking system failure (E-SYS), plus the task codes A-E1…D-E6. Counted per attempt. |
| **Failure (F)** | The participant believes they are done, but the end-state check fails (wrong person, dates, decision, contract terms, or a duplicate). |
| **Abandonment (A)** | The participant gives up, says they would ask someone, or reaches the time cap without the stop point. |
| **Time on task** | From the first input after "Start" to the stop event, from a screen recording or a stopwatch. Includes page loading. |
| **Help** | H1 neutral prompt (not counted) · H2 hint · H3 demonstration. |
| **Post-task ease** | SEQ, 1–7, after every attempt ([sus-method §3](x2/sus-method.md)). |
| **Request-state comprehension** | One question per task. The answer is compared with what the current app actually does. |
| **Overall** | Standard 10-item SUS after the last task ([sus-method](x2/sus-method.md)). |

| Script | Success end state | Task-specific errors | Abandonment signal | Defined in |
|---|---|---|---|---|
| A | exactly one new **Pending**, **annual**, **day** request; start = end = target date; reason not empty | A-E1…A-E6 (wrong type/mode/dates, selector revert, Cancel loses input, dismissed confirmation) | cannot find entry or get past validation; 5 min cap | [task-scripts · A](x2/task-scripts.md#task-a--request-leave) |
| B | the email or profile is the **target's id**, not a namesake | B-E1…B-E4 | 2 min cap; asks someone | [task-scripts · B](x2/task-scripts.md#task-b--find-an-employee) |
| C | target request **Rejected** with a reason; no other request changed; one decision | C-E1…C-E4 | 4 min cap; cannot locate request | [task-scripts · C](x2/task-scripts.md#task-c--review-a-request) |
| D | exactly one new contract; position (program, center), *Volunteer Contract*, Full Time, Onsite, start date, default end date, Sun–Thu 09:00–17:00 schedule and monthly pay match; nothing else changed | D-E1…D-E6 | 10 min cap; blocked by dependencies | [task-scripts · D](x2/task-scripts.md#task-d--create-a-contract-composer) |

## 4. Participants and procedure (planned, not yet run)

**Recruitment matrix (minimum to report a baseline).** The full quota by role × language × city, the screener, the counterbalanced run order, the facilitator guide and the pilot checklist are in [x2/recruitment-and-sessions.md](x2/recruitment-and-sessions.md).

| Role | Tasks | EN interface | AR interface | Cities |
|---|---|---|---|---|
| Employee | A | ≥ 1 | ≥ 1 | at least 2 different cities across the role |
| Manager | A, C | ≥ 1 | ≥ 1 | at least 2 |
| HR officer (`HR-<city>`) | A, B, C, D | ≥ 1 | ≥ 1 | at least 2 |

- **Minimum:** 6 participants (one per role × language cell) across at least 2 cities. **Preferred:** around 5 per role (about 15), which is the usual size for finding most frequent problems. It is still not a basis for statistical claims.
- Moderated, one-to-one sessions, about 45 minutes, in person or by screen share, on the disposable synthetic target ([task-scripts §0.1](x2/task-scripts.md)). Desktop first; a phone run is optional and reported separately.
- Task order is counterbalanced ([x2/recruitment-and-sessions.md §3](x2/recruitment-and-sessions.md)). One interface language per participant. SEQ after each task; SUS at the end.
- Recruiting participants, scheduling them and contacting staff are **actions for the UX researcher with HR's approval**. X2's executor did none of them (no contact was authorized).

**Pre-session checklist** (each item is a blocker for real sessions, not for this document):

1. A disposable target exists with the personas and states each script needs. **Prepared:** [x2/test-environment.md](x2/test-environment.md) builds it on SQLite outside the repository. Data-checked, then **walked in a browser in EN** for Tasks A–D on 2026-10-19 ([x2/dry-run-2026-10-19.md](x2/dry-run-2026-10-19.md)); the walkthrough found 7 instrument defects, all fixed (scripts v1.1, rebuilt data). Still to do: review, and the pilot in **both** languages (Arabic, the HMS personas and D′ were not walked). It must never be the operational MySQL. Before each participant the moderator skips the product tours per persona ([task-scripts §0.1](x2/task-scripts.md)).
2. The Arabic SUS/SEQ text is either replaced with the validated A-SUS or kept with the "unvalidated" label after the reviewer checklist ([sus-method §1.2–1.3](x2/sus-method.md)). The validated wording could not be verified from a legitimate source on 2026-10-19.
3. The HR product owner + privacy owner confirm the recording and retention defaults ([observations §1](x2/observations.md)).
4. The two questions under "Needs reviewer decision" are answered.
5. One **pilot** session (not counted) is run with the [pilot checklist](x2/recruitment-and-sessions.md#5-pilot-run-checklist-one-session-not-counted) to check timings, caps and the scenario wording. Any change is versioned in [task-scripts](x2/task-scripts.md#change-control).
6. The app SHA is recorded per session. If `main` gains a UI change (A3/A4/A5/A7/B0…) before the sessions run, the sessions measure the **new** SHA, and this document must say so.

## 5. Success measures: proposed targets (AC-X2-03)

> **These are PROPOSED TARGETS. They are not current results, not measured values, and not automatic release guarantees or gates.** No current value exists for either one (§6). X2-R checks whether they remain realistic once a real baseline exists.

| Measure | Proposed target | How it would be judged | Status |
|---|---|---|---|
| **Request leave** (Task A) | **under 60 seconds**: median time on task of **unassisted successful** attempts, from first input on the dashboard to the stop point, desktop profile, per language | Compared only on the same scripts version, same fixture type and same device profile. Reported with n and min–max. | **Proposed target. Not measured.** |
| **Employee lookup** (Task B) | **under 15 seconds**: median time on task of **unassisted successful** attempts, from first input on the dashboard to the stop point, desktop profile, per language | same | **Proposed target. Not measured.** |
| Completion | No target is proposed. The baseline S / SA / F / A counts are recorded first. | — | Not measured |
| SUS / SEQ | No target is proposed. A target from published norms would not suit the unvalidated Arabic form. | — | Not measured |

Notes:
- Time on task includes system time. X1 measured that the page-covering loader costs about 0.55–1.25 s on desktop and 1.8–4.3 s on the phone profile after content is already rendered ([performance-baseline.md](performance-baseline.md) §4, bottleneck 4). That is a **system** measurement and is not a usability result. It shows that part of each task's time is owned by A7.
- A target is met only by real observations. Missing participants or a missing baseline **cannot** be replaced by an estimate or an "improvement %" (X2-R AC-X2-R-01).

## 6. Baseline result (AC-X2-02)

> **PENDING USER RESEARCH. As of 2026-10-19 no baseline usability observations exist for HC-HR.**
>
> - Participants observed: **0**. Task attempts: **0**. SUS questionnaires: **0**.
> - Task success, time on task, errors, help, SEQ and SUS: **not measured**. [observations.md §4](x2/observations.md) is intentionally empty.
> - Nothing in this document or its attachments is a participant result.

**Why it is pending:**
1. No participants have been recruited or scheduled. Contacting staff was outside the authorization for this execution.
2. **The session target is prepared but not yet reviewed or piloted.** P0 §3 recorded that there is no staging host and no browser-QA database. X2 now has its own session copy: the X1 synthetic build plus the states the scripts need, in two cities (ALP, HMS), built outside the repository on SQLite ([x2/test-environment.md](x2/test-environment.md)). X1's files are reused unchanged. It was checked against the app's scopes and walked in a browser in English on 2026-10-19 ([x2/dry-run-2026-10-19.md](x2/dry-run-2026-10-19.md): an instrument check by the executor, not a usability result). It still needs the review in [x2/review-checklist.md](x2/review-checklist.md) and the bilingual pilot.
3. The Arabic SUS/SEQ text is not yet the validated version (§4 checklist item 2).
4. The two interpretation questions at the top of this document are open.

**What can be said without users** (source-derived **hypotheses**, not findings; each will be confirmed or refuted only by observation):

| Hypothesis | Task | Source evidence | Related task |
|---|---|---|---|
| The leave type may revert to the placeholder in Arabic after another field updates. | A | plan A3-R; Select2 `wire:ignore` usage in `employee/leave/index.blade.php` | A3-R |
| The leave modal's *Cancel* is a link to `leaves.index`, and the backdrop/Escape dismiss immediately, so input can be lost. | A | `employee/leave/index.blade.php` (Cancel `:href="route('leaves.index')"`); plan A5-M. The link was also seen in the live DOM in the walkthrough. | A5-M |
| The leave success toast does not say the request is pending or who acts next, so the comprehension answer may be wrong. | A | walkthrough: "Operation completed successfully" | A3, B0 |
| The composer saves auto-renew **on** without asking, so HR may not know a new contract renews itself. | D | walkthrough: `contracts.auto_renew` = 1 on the created contract | A6 |
| The leave entry button says *Add New* / *إضافة جديد* rather than "Request leave", and the guide uses a third wording. | A | `resources/lang/*/general.php` `add-new`; guide §7.1 | A5-M (labels), A4, D3 |
| The full-page loader adds wait time to every task. | all | X1 §4, bottleneck 4 | A7 |
| On `/employees` the table renders twice after load (about 2.0 s of server time), and a search typed in that window queues behind it. This makes the 15 s lookup target sensitive to system time. | B | X1 §4, bottleneck 3 | X1-I, B4 |
| Managers cannot open the employee list, so Task B is HR-only today. | B | `config/access-control.php` | C0 / C2 (scope) |
| A rejection without a reason produces a validation error. Participants may try *Accept* or *Seen by HR* first. | C | `LeaveRequestForm::submitDecision` (`reject_reason` required on reject); the `seen-by-hr` column | C3, B6 |
| Position choice depends on city/program fields and seat capacity, which may block progress without explanation. | D | composer `locked-because` / `no-seats-left` messages; plan A6 step 2 | A6 |

## 7. Limitations

- **No real participants.** This is the central limitation. Until sessions run, X2 provides instruments only, and downstream "before" comparisons do not exist.
- **Sample size.** Even the preferred around-15 participants support descriptive counts and medians, not statistical proof. Results are reported with n, raw counts and ranges.
- **Arabic SUS/SEQ unvalidated** (working translation v1.0) until replaced with the published A-SUS (AlGhannam et al., 2018). Its wording could not be obtained from a legitimate source on 2026-10-19 ([sus-method §1.2](x2/sus-method.md)).
- **UX report PDF and original v2 file unavailable.** Scripts were derived from R-15, the task names and the source code.
- **User guide out of date** in places (§1.1), so the "expected paths" come from code, not the guide.
- **Synthetic setting.** Synthetic personas and data, plus sessions with a moderator present, differ from daily work, e.g. real workload and real names.
- **Desktop first.** The phone profile is optional at baseline. Phone results come mainly from M1/G-B.
- **The baseline moves with `main`.** Sessions must record their SHA. A UI change merged before the sessions changes what is measured.

## 8. Assumptions and choices made (routine, reversible)

| ID | Choice | Why | Reverse by |
|---|---|---|---|
| X2-AS-1 | "Create a contract draft" = prepare and create a new contract in the composer. The contract **type Draft** (reservation) is variant D′ only. | SHARED RULES require separating employee draft / inactive / contract Draft type. The composer has no "save as draft" action. Raised as **RD-1** at the top of this document. |
| X2-AS-2 | Task C uses a **rejection** with a given reason, not an approval. | It exercises the decision modal's required-reason path and gives an unambiguous correct end state. Raised as **RD-2** at the top of this document. |
| X2-AS-3 | Outputs live in `docs/ux/x2/` beside the main document. | Keeps the plan's named path for the main file, and keeps the instruments versionable. | Move the files; update links. |
| X2-AS-4 | Recording retention of 12 months / end of X2-R. | Proposed default. DEC-08 (one year) concerns in-app feedback, not research recordings. | HR + privacy owner decision before the first session. |

No DEC item blocks X2, and no business invariant was contradicted, so nothing required stopping.

## 9. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-X2-01** All four task scripts have observable success, error and abandonment definitions. | **Met (document)** | §3 table; [task-scripts](x2/task-scripts.md) §0.3–0.4 shared definitions, plus, for each of A, B, C and D: starting screen, scenario cards (EN/AR), stop point, time cap, numbered end-state check, task error codes, abandonment signals. |
| **AC-X2-02** Real baseline observations or an explicit pending-user-research limitation are recorded. | **Met: limitation recorded** | §6 (0 participants, reasons, what is needed); [observations.md](x2/observations.md) header and empty §4 tables. No participant, time or SUS value appears anywhere. |
| **AC-X2-03** Leave under 60 s and lookup under 15 s are proposed targets, not current results or automatic release guarantees. | **Met** | §5 banner and table ("Proposed target. Not measured."); [task-scripts](x2/task-scripts.md) A and B repeat the label. |

Review package: [x2/review-checklist.md](x2/review-checklist.md) (what to check, evidence per AC, A1 sign-off dependency).

Verification performed: a document review against the three ACs, plus cross-checks of every label, route and permission cited in the scripts against the source at `6b96806d`. No application tests are claimed from this review. Self-review on 2026-10-19 re-checked all X2 files against the three ACs: no participant, time or SUS score appears in any file (the only SUS number is the labelled arithmetic illustration in [sus-method §2](x2/sus-method.md)); stale "fixture gap" statements were updated to point to the session environment. The scripts were then walked once on the session copy in English ([x2/dry-run-2026-10-19.md](x2/dry-run-2026-10-19.md)): every expected path, stop point and end-state check of A–D was reached or checked; the defects it found are fixed (scripts v1.1). **Formal review (QA engineer + technical lead): pending.** X2 is **not** marked done.

## 10. Handoff to consumers

**General rule for A5, A4, A7** (SHARED RULES: "Capture X1/X2 baselines before the first UX change"): the X2 *instrument* is ready, but the X2 *baseline* is not. The technical lead decides one of the following before the first A5/A4/A7 UI change merges:
- **(a) wait** for the baseline sessions (§4 checklist, §6 prerequisites); or
- **(b) proceed** and record, in each task's evidence and later in X2-R, that no pre-change usability baseline exists for that delta (AC-X2-R-01 allows this disclosure; inventing one is not allowed).

Source-only work for these tasks can continue either way.

| Consumer | What X2 gives it | What it must do with it |
|---|---|---|
| **A5** (dialogs and controls accessibility) | Tasks A and C run through the shared modal (leave form, decision modal, confirm dialog). Codes E-LOSS, A-E5, A-E6 and C-E2 capture modal failures. | Keep these flows scriptable: no change to task stop points without versioning the scripts. If (a), A5's after-check reruns Tasks A and C. Keyboard/screen-reader checks stay A5's own evidence; X2 does not cover assistive tech at baseline. The walkthrough also saw radios named by their values ("1", "5", "3") and day lists named only "Select" in the contract composer ([dry-run §5](x2/dry-run-2026-10-19.md)). |
| **A4** (EN/AR completeness) | Every script has EN and AR scenario cards. Results are split by interface language. B′ (cross-script search) exists as an optional variant. The guide/label mismatch is in §1.1 and §6. | Compare EN vs AR outcomes per task once data exists. Use the A4 Arabic content reviewer to review the Arabic SUS/SEQ working translation (or the swap to A-SUS) and the AR scenario cards. Labels changed by A4 must be reflected in the scripts' "expected path" (a versioned change). The walkthrough noted Latin "AM/PM" in the composer's Arabic wording preview and a hard-coded EN/AR sidebar heading ([dry-run §5](x2/dry-run-2026-10-19.md)). Arabic paths are not yet walked. |
| **A7** (artificial waiting) | Time on task includes the loader. X1 §4 (bottleneck 4) quantifies the system part. The leave and lookup **proposed** targets (§5) are the user-level outcome A7 contributes to. | Report A7's improvement in X1's system terms. Do **not** claim a time-on-task improvement unless X2 sessions ran before and after on the same scripts version. |
| **X2-I** (optional task-outcome telemetry; DEC-10 settled: anonymous, own server, 90-day retention; still also blocked on A2) | Task IDs A–D, start and stop events, outcome codes S/SA/F/A, abandonment rules (caps and give-up), and error categories, all defined observably. | Derive its attempt model from these definitions so moderated and instrumented results are comparable. Note what telemetry **cannot** see: H-levels, comprehension and the end-state check of "wrong but completed" (F). Collect no names, form values, reasons or pay (X2-I step 3). Moderated research stays valid whether or not telemetry is built. |
| G-B, G-C, X2-R (later) | The fixed instrument (scripts v1.1 at the time of writing) and the empty record. | Rerun the same scripts version, disclose any version or sample differences, and compare only with real baseline data or state that it is missing. |
