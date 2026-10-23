# X2 · Review checklist (QA engineer + technical lead)

Part of [usability-baseline.md](../usability-baseline.md). Task X2 (R-15).
X2 stays **not done** until this review is signed **and** real sessions are recorded. Every result is **PENDING USER RESEARCH** until then.

## 1. Sign-off dependency

| Dependency | State on 2026-10-19 | Effect on X2 |
|---|---|---|
| **A1** test baseline ([test-baseline.md](../test-baseline.md)) | Delivered, all ACs show evidence, **review sign-off missing** | X2 cannot be signed before A1 is signed. If A1's review changes the starting SHA, update [usability-baseline §1](../usability-baseline.md) and the scripts header. |
| P0 start state ([start-state.md](../start-state.md)) | Awaiting review | Same as A1 for the SHA. |
| Two open interpretations ([usability-baseline, "Needs reviewer decision"](../usability-baseline.md#needs-reviewer-decision)) | Open | Must be answered before the pilot. If either answer differs from the current scripts, Tasks C/D change and the scripts move to v1.2 (v1.1 already holds the walkthrough corrections). |

## 2. What to check, and where the evidence is

| AC / item | Check | Evidence location |
|---|---|---|
| **AC-X2-01** scripts have observable success, error and abandonment definitions | For each of A, B, C, D: starting screen, scenario card (EN + AR), stop point, time cap, numbered end-state check, task error codes, abandonment signals. Shared S/SA/F/A, help and error rules. | [task-scripts.md](task-scripts.md) §0.2–0.4 and each task section; summary in [usability-baseline §3](../usability-baseline.md) |
| **AC-X2-02** real observations **or** an explicit pending limitation | 0 participants stated; record tables empty; no participant, time, success rate or SUS score anywhere. | [usability-baseline §6](../usability-baseline.md); [observations.md](observations.md) header and §4; [observations-template.csv](observations-template.csv) (header row only) |
| **AC-X2-03** 60 s / 15 s are proposed targets, not results or guarantees | Banner and "Proposed target. Not measured." label; repeated in Tasks A and B. | [usability-baseline §5](../usability-baseline.md); [task-scripts.md](task-scripts.md) Tasks A and B |
| Expected paths match the app | Spot-check labels, routes and permissions cited per task against the source at the recorded SHA. | [task-scripts.md](task-scripts.md) "Expected path" rows; [usability-baseline §1.1](../usability-baseline.md) |
| Walkthrough on the session copy | Read the record; confirm it is labelled an instrument check, holds no timings or participant data, and that each defect in its §3 is reflected in scripts v1.1 or `session-states.php`. Optionally repeat one task. | [dry-run-2026-10-19.md](dry-run-2026-10-19.md); [task-scripts change control](task-scripts.md#change-control) |
| SUS method | Standard items and scoring; Arabic labelled **unvalidated**; A-SUS search recorded; reviewer checklist present. The worked example (75.0) is labelled as arithmetic only. | [sus-method.md](sus-method.md) §1.2, §1.3, §2 |
| Session environment is isolated | Read the three files; confirm `x2-home.php` refuses a home inside the repo and `env.php`'s `perf_assert_isolated()` runs before any write. Optionally run [test-environment §3](test-environment.md) steps 1–4 on a scratch folder. | [test-environment.md](test-environment.md); `tests/Usability/Baseline/*.php`; check results in test-environment §2.1 |
| X1 files untouched | `git status` / diff shows no change under `tests/Performance/Baseline/` or `docs/ux/performance-baseline.md`. X1 owner agrees to the reuse. | git |
| Recruitment and session logistics | Screener excludes builders and asks nothing sensitive; quota covers role × language × 2 cities; counterbalancing; facilitator steps; pilot checklist. No contact made. | [recruitment-and-sessions.md](recruitment-and-sessions.md) |
| Privacy | Participant codes only; city rule; no names, pay, recordings in the repo; retention default awaits HR + privacy owner. | [observations.md §1](observations.md); [usability-baseline §8](../usability-baseline.md) X2-AS-4 |
| No product change | No change in `app/`, `database/`, `resources/`, `routes/`, `config/`. | git |

## 3. Sign-off

| Reviewer | Decision | Date | Notes |
|---|---|---|---|
| QA engineer | *(pending)* | | |
| Technical lead | *(pending)* | | |

Signing this checklist approves the **instruments**. It does not mark X2 done: that still needs real sessions recorded in [observations.md](observations.md).
