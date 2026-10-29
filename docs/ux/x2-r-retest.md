# X2-R · Repeat usability testing and remaining friction

Version 1.0, 2026-10-28. Task X2-R in [ux-prompts-v3.md](ux-prompts-v3.md) (R-15). Owner role: UX researcher + QA lead. Consumer: G-R.
Repository HEAD when written: `7d340617` (`phase6-rit`). No product code, schema or data was changed; this file is the only change.

**Status: INSTRUMENT AND REGISTER DELIVERED. RE-TEST OBSERVATIONS PENDING USER RESEARCH. Awaiting review (QA engineer + technical lead). Not marked done.**

> **No participant has been observed, before or after.** There is no baseline ([usability-baseline.md](usability-baseline.md) §6: 0 participants, 0 attempts, 0 SUS) and no re-test. Every cell below that would hold a count, median, SEQ or SUS is **not measured**, which is not zero. No improvement or regression percentage is stated anywhere in this file, because none can be computed.

## 1. Prerequisites checked (delivered artifacts, not chat history)

| Prerequisite | Artifact found | State | Effect on X2-R |
|---|---|---|---|
| X2 (instrument) | `docs/ux/x2/*` (scripts v1.1, observations, SUS method, environment) | Delivered; review pending; sessions not run | The instrument to repeat exists. The baseline it should be compared with does not. |
| X2-I (telemetry) | [x2-i-implementation.md](x2-i-implementation.md) | Built; **collection off by default**, never switched on in production (X2I-Q3) | No telemetry data exists to cross-check. |
| X1-I | Commits `1b5f4165`, `e6b2f46c` (lost-write lookup, auth memo, avatars, table init). **No `x1-i-implementation.md` and no re-run of the X1 harness found.** | Partial: code present, no after-measurement artifact | System-time part of the targets (§5) cannot be compared with X1. |
| G-C | [g-c-gate.md](g-c-gate.md) | Corrections built, matrix re-run recorded (§7 there); formal review pending | Source of the register in §6. Moderator walkthrough only. |
| D1 | [d1-implementation.md](d1-implementation.md) | Built and tested; review pending | Not walked by any user. |
| D2 | [d2-implementation.md](d2-implementation.md) | Built and tested; review pending | Phone self-service not walked by any user. |
| D3 | [d3-feedback-notes.md](d3-feedback-notes.md), [d3-help-notes.md](d3-help-notes.md) | Built; help text PROPOSED, content review pending | Help/feedback not tested with users. |

Answered 2026-10-28 by the user in chat: **RD-1 = new contract in the composer; RD-2 = reject with a reason** (both the recommended options, so scripts v1.1 stay unchanged; the baseline-document record of the answer is still to be updated). Still unresolved from X2: the Arabic SUS/SEQ validation, and A1's review. Changing any of them after the first counted session breaks comparability.

**Consequence:** X2-R cannot be marked done. AC-X2-R-01 is satisfied by *disclosure* of the missing baseline (it explicitly allows this); AC-X2-R-02 and the "observed" part of AC-X2-R-03 are not satisfied until sessions exist.

## 2. Comparability design for the repeat (what must match)

The re-test runs [x2/task-scripts.md](x2/task-scripts.md) at the version recorded with the baseline sessions. Differences are listed per session and in the report, never averaged away.

| Condition | Baseline requirement | Known difference today |
|---|---|---|
| Scripts version | Same (v1.1 or later, versioned) | If the baseline is run on v1.1, the re-test must use v1.1 too; labels changed by A4/A5-M/A3 since 2026-10-19 may need a versioned script change first. |
| Roles / cities / language | employee, manager, HR × EN/AR × ≥ 2 cities ([recruitment §](x2/recruitment-and-sessions.md)) | Same quota; Arabic and HMS paths were never walked at baseline. |
| Device | Desktop first; phone reported separately | D2 changed phone self-service, so a phone comparison needs its own baseline. |
| Data | Disposable synthetic copy ([x2/test-environment.md](x2/test-environment.md)) | Must be rebuilt for the new SHA; record SHA per session. |
| SUS/SEQ | Same wording, same form language | Arabic is the unvalidated working translation unless replaced; report with that label. |
| Participants | Same recruiting rules | Prefer new and returning participants reported separately; **prior exposure to the old UI or to the tour is a stated sample difference.** |

Sample differences are written in the session sheet (`Prior HC-HR use`) and summarized in §3 once data exists.

## 3. Results tables (empty until sessions exist)

Fill only from [x2/observations.md](x2/observations.md) §4.2–4.4 for the baseline and the re-test, same task and language. Time is the median and min–max over unassisted successes only. Show raw counts first.

| Task | Language | Baseline n / S / SA / F / A | Re-test n / S / SA / F / A | Median time S (min–max), base → re-test | Errors by code | Help H2/H3 | Median SEQ | Comprehension matched |
|---|---|---|---|---|---|---|---|---|
| A · Request leave | EN | not measured | not measured | — | — | — | — | — |
| A · Request leave | AR | not measured | not measured | — | — | — | — | — |
| B · Find an employee | EN / AR | not measured | not measured | — | — | — | — | — |
| C · Review a request | EN / AR | not measured | not measured | — | — | — | — | — |
| D · Create a contract | EN / AR | not measured | not measured | — | — | — | — | — |

| Overall | Baseline | Re-test |
|---|---|---|
| SUS (per form language, n, median, range) | not measured | not measured |

Reporting rules for when data exists:
- A **regression** is reported when a measure is worse on the same task and language, with the raw counts beside it. With n of about 5 per cell, differences are descriptive; no significance claim.
- A task with fewer than 3 attempts per cell is reported as counts only, with no median.
- Request-state comprehension is reported per task as matched / asked, against what the app does at the re-test SHA (the next-step text from A3/B6 changed what is "correct").
- If baseline cells stay empty, the comparison column reads "no baseline" and the re-test is reported on its own, against the *proposed* targets only.

## 4. What evidence exists today, and what it can and cannot say

| Source | Covers | Cannot say |
|---|---|---|
| X2 dry run, 2026-10-19 | Moderator walkthrough, EN only, tasks A–D on the old SHA | Any user result; Arabic; HMS |
| G-C §2 and §7 | Moderator runs: A ×2, B ×2, C ×2 succeeded; D entry only. All C0 entry points reached in 0–2 clicks | Time, errors, help, comprehension, SEQ, SUS; independence (same agents as the implementers) |
| X1 performance baseline | System time per flow, 5-run medians, synthetic | User outcomes. Its after-run for X1-I does not exist. |
| X2-I telemetry | Schema and report, tested on synthetic data | Real data: collection never enabled in production. Also blind to help, comprehension and F. |

These are not substitutes for sessions and are not counted as attempts.

## 5. Are the proposed targets still realistic?

Targets (proposed, never measured): leave request **under 60 s**, employee lookup **under 15 s**, as the median of unassisted successes, desktop, per language. No current value for either exists, so **realism cannot be confirmed from users**. What can be said from source and system measurements:

| Target | System floor (X1, warm desktop, synthetic) | Reading |
|---|---|---|
| Leave, 60 s | Leave form open ≈ 0.7–1.1 s; confirm step adds one more modal. X1 measured the old page-covering loader at 0.55–1.25 s; A7 removed the artificial delays but there is no after-measurement. | System time is a small part of 60 s. The time is dominated by typing a date and reason, choosing a type, and reading the confirmation. Plausible for a practised user; **unverified for a first-time one**, and the G-C walkthrough did not time anything. Open hypothesis: the Arabic type selector revert (A3-R). |
| Lookup, 15 s | Employee search interaction 1.4 s warm (debounce 0.5 s); `/employees` first useful content 3.1 s warm, 4.5 s first visit, 7.6 s cold (X1 §3.2). X1-I's table init change was not re-measured. | System time alone can consume 3–8 s of 15 s on a first or cold load, so the target is **sensitive to the server and cache state**; it is realistic only if the cold-start path is fixed or sessions are run warm. Header search (C2) is a second route that was not timed. |

Neither target should be reported as met or missed. Disposition (proposed): keep both as *proposed targets*, re-state them in G-R as "not measured", and re-run X1's harness after X1-I so the system share is known before the first session.

## 6. Prioritized follow-up register

Severity is the likely effect on completing the task (High: blocks or produces a wrong result; Medium: slows or confuses; Low: cosmetic or edge). **Frequency is not measured for any item**, so rank within a severity is by task reach (how many core tasks and roles it touches), stated as such. Items come from existing evidence only; each stays a hypothesis for the re-test until observed. Owners are roles, proposed; dispositions are proposals for the technical lead and HR product owner.

| # | Friction | Source | Sev. | Reach | Owner (proposed) | Proposed disposition |
|---|---|---|---|---|---|---|
| R-1 | No usability baseline or re-test exists; every user-level claim is unmeasured | usability-baseline §6 | **High** | all tasks | UX researcher + HR product owner | Recruit with HR approval; run baseline on a fixed SHA, or formally record option (b) of baseline §10 (proceed without baseline). Needs RD-1/RD-2 answered first. |
| R-2 | Tasks D (full end state) and A/B/C in AR and phone mostly unwalked; HMS, general manager, super-admin not covered | G-C §2 | **High** | A, B, C, D | QA lead | Walk before sessions as instrument check; then cover in sessions. |
| R-3 | Cancel in the leave form is a link that reloads the page; telemetry therefore reads such attempts as abandoned | X2-I §8; A5-M | Medium | A | Frontend developer | Verify A5-M local close; add the one-line `finishTaskAttempt('cancelled')`. |
| R-4 | Leave type may revert to the placeholder in Arabic after another field updates; list reopens after keyboard selection (G-C-14) | baseline §6; G-C-14 | Medium | A (AR) | Frontend/Livewire developer | Confirm in AR sessions; fix by A3-R owner if seen. |
| R-5 | Composer saves auto-renew on without asking | baseline §6; G-B-10 | Medium | D | Backend + HR product owner | Check that the G-B-10 box exists and is understood in D sessions. |
| R-6 | Rejection needs a reason; participants may try *Accept* / *Seen by HR* first | baseline §6 | Medium | C | Frontend developer | Observe C-E1…C-E4; adjust labels only if errors recur. |
| R-7 | Help text and feedback channel are proposed and unreviewed; Arabic guide still describes removed contract types | d3 notes; baseline §1.1 | Medium | all | HR product owner + Arabic reviewer | Complete content review before sessions; update the guide. |
| R-8 | Arabic SUS/SEQ is an unvalidated working translation | sus-method §1.2 | Medium | SUS | UX researcher + Arabic reviewer | Replace with validated A-SUS or keep the "unvalidated" label in every report. |
| R-9 | Contract composer a11y: radios named "1", "5", "3"; day lists named only "Select"; Latin AM/PM in the Arabic preview | dry-run §5 | Medium | D | Frontend developer | Verify after A5/A4; screen-reader check is separate evidence. |
| R-10 | `/employees` renders its table twice after load; searches typed meanwhile queue | X1 §4, baseline §6 | Medium | B | Backend + frontend | Re-measure after X1-I; the code change exists, the after-number does not. |
| R-11 | Managers cannot open the employee list; Task B is HR only | access-control, baseline §6 | Low | B | HR product owner | Not a defect; keep documented. Record if managers ask for it in sessions. |
| R-12 | Centers page 500 for a manager on SQLite (D-10); F11 calendar "my reports" from positions | G-C-14, G-C-13 | Low | edge | Backend developer | Check on MySQL before acting. |
| R-13 | G-A, G-B, G-C, X2, X2-I, D1–D3 formal reviews all pending | each note | Medium | release | Technical lead + QA engineer | Complete before G-R. |

## 7. Limitations

- No participants; no baseline; no re-test; no SUS. Synthetic setting and a moderator change behaviour relative to daily work.
- G-C and the dry run were run by the implementing agents, not independent testers.
- The UX report PDF and the original v2 file were unavailable to X2.
- X1-I has no after-measurement; system-time effects are unquantified.
- Findings in §6 are hypotheses from source and walkthroughs, not observed frequencies.
- Nothing here approves a policy change; business rules are unchanged.

## 8. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-X2-R-01** Results compared with the same baseline, or the missing baseline disclosed | **Met by disclosure** | Banner, §1, §3: baseline absent, no percentages, comparability rules §2 |
| **AC-X2-R-02** Core tasks and request-state understanding have observable evidence across roles/locales | **Not met** | §3 empty; only moderator evidence exists (§4). Needs sessions. |
| **AC-X2-R-03** Remaining friction ranked by severity and frequency with owner and disposition | **Partly met** | §6 ranked by severity and reach with owners and proposed dispositions; frequency not measured and re-ranks after sessions |

Verification: document review against the three ACs, with cross-checks of the cited files and commits. No application tests were run or claimed.

## 9. Handoff to G-R

G-R must cite: no usability baseline and no re-test exist; both targets are unmeasured; X1-I lacks an after-measurement; the §6 register and its pending reviews. To close X2-R: answer RD-1/RD-2, review the instrument, run baseline and re-test sessions on recorded SHAs, fill §3, re-rank §6 by observed frequency.
