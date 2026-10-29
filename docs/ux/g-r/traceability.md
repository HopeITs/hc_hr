# G-R · Acceptance traceability

Part of release gate G-R in [ux-prompts-v3.md](../ux-prompts-v3.md) (AC-G-R-01, AC-G-R-02). Integrated SHA under review: **`81c5d28f`** (branch `g-r`). Written 2026-10-29. Read-only pass: no code, data, schema or test was changed; this file is the only output.

**Rule used throughout:** a status is copied from the delivered document, not inferred from source. "Implemented" or "tests exist" is not "accepted". A task is accepted only when its document records the named review; almost none does. Where a document gives evidence but no status word, the cell says *evidence, no status label*.

**Sources:** task index `ux-prompts-v3.md` lines 92–142, decisions table lines 32–45, each task's §9 AC lines (e.g. 207–211 … 4091–4095) and Requirements lines, the requirement map lines 4122–4145; `docs/ux/*.md`, `docs/ux/c0/*`, `docs/ux/x2/*`, `docs/ux/evidence/*`; `git log --oneline` on `81c5d28f`.

---

## 1. Task → deliverable → commits → AC status → review

Review column: "awaiting" = the document's own header says awaiting/pending review and no sign-off is recorded. Only **P0, A1, A2-D, A3-D** carry a recorded approval (2026-10-20, HR product owner + technical lead).

| # | Task | Delivered doc(s) | Commit(s) | AC status as stated | Review | Open items (from the doc) |
|---|---|---|---|---|---|---|
| 1 | P0 | [start-state.md](../start-state.md) | fb01a226, 94d3b248, 904ee63c | P0-01 Met · P0-02 Met · P0-03 Met for automated tests; browser QA DB/staging "pending limitations" (§7) | **Approved 2026-10-20** (HR PO + TL); QA role not assigned | No CI, no staging (§8, accepted limitation); real data remains in git history of `config/samples/sampleData.php` (§8); browserless token in git history (§8) |
| 2 | A1 | [test-baseline.md](../test-baseline.md) | fb01a226, 94d3b248 | A1-01 Met · A1-02 Met · A1-03 Met (§7) | **Approved 2026-10-20** | §6 browserless token: removed from source, rotation user-attested only (G-A §3) |
| 3 | X1 | [performance-baseline.md](../performance-baseline.md), [evidence/x1](../x1) | c28b9de7, c26869e3, f0584960, 46f3032e, 6b96806d | X1-01 Met · X1-02 Met · X1-03 Met (lines 242–244) | Awaiting (line 256 "pending") | No staging, no field data (line 236) |
| 4 | X2 | [usability-baseline.md](../usability-baseline.md), [x2/*](../x2) | 43a4d441 | X2-01 Met (document) · X2-02 **Met: limitation recorded — 0 participants** · X2-03 Met (lines 186–188) | Awaiting (line 14) | Arabic SUS/SEQ unvalidated; reviewer questions (line 3, 110–112); RD-1/RD-2 answered in chat 2026-10-28 but not written back (x2-r-retest.md line 22) |
| 5 | A2-D | [access-policy.md](../access-policy.md), [a2-d-decision-sheet.md](../a2-d-decision-sheet.md) | 50b5df32, aceda68e | A2-D-01 Met · A2-D-02 Met · A2-D-03 Met (lines 212–214) | **Approved 2026-10-20** | Accepted risk: guessable card link (§9 Q6) |
| 6 | A3-D | [leave-calculation-contract.md](../leave-calculation-contract.md), [a3-d-decision-sheet.md](../a3-d-decision-sheet.md), [decisions.md](../decisions.md) | aceda68e | A3-D-01, -02, -03 Met (line 259) | **Approved 2026-10-20** | — |
| 7 | A6-D | [contract-failure-triage.md](../contract-failure-triage.md) | d2ecb25c, cd045c53 | Evidence, no status label. A6-D-01: 4 failures with cause. **A6-D-02: real export path not exercised** ("no PDF/PNG evidence", line 22). A6-D-03: tests named | None recorded | Real render gap (line 22) |
| 8 | B5-D | [employee-lifecycle.md](../employee-lifecycle.md), [b5-d-decision-sheet.md](../b5-d-decision-sheet.md) | 90cfdcee | B5-D-01 Met · B5-D-02 Met · B5-D-03 Met (decided 2026-10-20) (lines 204–206) | Awaiting TL (line 3) | Duplicate-ID message shows other-city/former record name — security-reviewer note (line 133) |
| 9 | A2 | [a2-implementation.md](../a2-implementation.md) | dd33bdb1 | A2-01 Met (tests) · A2-02 Met (tests) · A2-03 Met (tests) (lines 65–67) | Awaiting (line 3) | No authenticated browser walkthrough (line 71; G-A #6); §5 open points: birthdays (private tier) shown to all staff, city hotline editable without city scope, program/center lists include former employees |
| 10 | A5 | [a5-implementation.md](../a5-implementation.md) | d8effd8c | Evidence, no status label (A5-01/02/03 §3). Doc: **"not done"** until screen-reader pass (line 4) | Awaiting | Screen-reader pass; select2 `aria-invalid` missing; URL keeps `?leave=…&mode=decision` (§4) |
| 11 | C5-D | [team-coverage-contract.md](../team-coverage-contract.md) | 6b3df356 | C5-D-01 Met · C5-D-02 Met (design) · C5-D-03 Met (design) (lines 259–261) | HR decisions recorded; **TL review of §4/§6 pending** (line 5) | — |
| 12 | A3-R | [a3-r-implementation.md](../a3-r-implementation.md) | 22318007, 1d53c1f1 | Evidence, no status label (§4, all runs pass) | Awaiting (header still says "not committed" — stale) | §5 found-not-fixed items routed to A3/A4/B2 |
| 13 | A4 | [a4-implementation.md](../a4-implementation.md) | bb71b430, 353f8570, 1a6d7d68 | Evidence, no status label (§3.1–3.3). Doc: **"not done"** — human Arabic content review pending (line 4) | Awaiting QA/TL **and Arabic content reviewer** | §4.3: content review; Latin names in stored notifications not isolated; multi-role switcher not walked |
| 14 | B0 | [feedback-contract.md](../feedback-contract.md) | ea6c79d0, 441f6508, 80f2d456 | B0-01 Met · B0-02 Met (**screen-reader pass pending**) · B0-03 Met (lines 135–137) | Awaiting (line 3) | §5.1: 13 reference-table targets on coarse permission only (→ G-A #4) |
| 15 | X2-I | [x2-i-implementation.md](../x2-i-implementation.md) | 42daa58f | X2-I-01 Met · X2-I-02 Met · X2-I-03 Met (lines 115–117) | Awaiting (line 3) | Collection **OFF**; X2I-Q1…Q4 unsettled, defaults applied (§7); cancel-as-abandon (X2-R R-3) |
| 16 | A3 | [a3-implementation.md](../a3-implementation.md) | 5ad4cb90, 72fe76b4 | A3-01 pass (8 data sets) · A3-02 pass · A3-03 pass 13/13 (lines 67–74); browser §9 | Awaiting (line 4) | §5: F11 maternity across December; forged `type=hour`; December double approval "C3"; service-agreement absence has no home; 8 h-day entitlement wording |
| 17 | A6 | [a6-implementation.md](../a6-implementation.md) | cd045c53, d2ecb25c (merged 45efd4fd, cfe3dd50) | **A6-01 Open** · A6-02 Covered by tests · A6-03 Covered by tests (lines 27–29). Doc: "not done until that evidence exists" | Awaiting | Real Arabic/English PDF render never evidenced; G-A §3 relies on product-owner attestation |
| 18 | A7 | [a7-implementation.md](../a7-implementation.md) | 441f6508 | A7-01 Met · A7-02 Met · A7-03 Met, with one hand-off to B3 (lines 87–89) | Awaiting (line 91) | Failure message hand-off to B3 |
| 19 | A5-M | [a5m-implementation.md](../a5m-implementation.md), [evidence/a5m](../a5m) | 8fd318c3, 6495cd72, 9d413de1, 1a54094f, 069c668e, 8141fdc7 | 01–04 Passed · 05 Passed (scroll check 0→0, weak) · **06 server tests only, not browser** · **07 passed except screen reader "not done"** · 08 Passed (lines 57–64) | Awaiting (line 4) | Screen-reader pass |
| 20 | G-A | [g-a-gate.md](../g-a-gate.md) | 2375ac49 (SHA `8141fdc7`) | No AC table. Result **PASS WITH CONDITIONS** (line 7); §4 no critical defect; browser evidence kept in scratchpad, **not committed** (line 22) | Item 9: prerequisite reviews still required | Items #1–#10 (§5); #2, #3, #4, #7, #9, #10 still open (see §4 below) |
| 21 | B1 | [b1-navigation-inventory.md](../b1-navigation-inventory.md), [b1-lifecycle-audit.md](../b1-lifecycle-audit.md) | d4944019 | B1-01 static inventory only. **B1-02 and B1-03 "remain open"** — no browser evidence (inventory line 4; audit: "nothing below is browser-verified") | None recorded; no implementation/AC doc | Every audit row "needs browser proof"; duplicate Flowbite copies left |
| 22 | B4-D | [table-contract.md](../table-contract.md) | fcaf0357, b0a909a7 | B4-D-01 Met (decided) · B4-D-02 Met · B4-D-03 Met (lines 214–216) | Awaiting (line 3) | — |
| 23 | B6 | [b6-implementation.md](../b6-implementation.md) | df99eeb9, bad24c44 | Evidence (tests), no status label; B6-03 grouped-row navigation "not covered" (lines 46–48) | **Not reviewed**, browser checks pending (line 4, 54) | No EN/AR/phone/screen-reader check |
| 24 | B2 | [b2-implementation.md](../b2-implementation.md) | 449d2810 | B2-01 Met · B2-02 Met · B2-03 Met (lines 143–145) | Awaiting (line 3) | Employee-create replay relies on unique `username`; §6 claim "national_number unique index dropped" looks **stale** — `2026_08_07_000002_revert_employee_pii_encryption` `up()` re-adds `unique('national_number')`; verify on MySQL |
| 25 | B3 | [b3-implementation.md](../b3-implementation.md) + 3 notes | 944ea35a | Evidence (tests), no status label (lines 29–31) | **Not reviewed; browser checks pending** (line 4) | No screen reader, no slow-network browser run, "Nothing committed" note stale |
| 26 | B4 | [b4-implementation.md](../b4-implementation.md), [evidence/b4](../b4) | 8e66e5f2, 55ce0766, ede68380, 25547cb6 | Evidence (B4-01/02/03), no status label | Formal review pending (header "uncommitted" — stale) | Employees Excel export has no server-side permission check (pre-existing); free-text column search unchecked |
| 27 | B5 | [b5-implementation.md](../b5-implementation.md) | ccb3575b | B5-01 Met · B5-02 Met · B5-03 Met (lines 191–193) | Awaiting (line 3) | **Live migration not run**; DEC-04 autosave (phase 2) not built (§6 #3) |
| 28 | M1 | [m1-shell-leave-notes.md](../m1-shell-leave-notes.md), [m1-employee-contract-notes.md](../m1-employee-contract-notes.md) | 19cbdb51, ee37380a | Evidence, no status label; **M1-02: real virtual keyboard, 200 % zoom, screen reader "Not tested"** (shell line 39) | QA + TL review pending (line 5) | Real-device check |
| 29 | G-B | [g-b-gate.md](../g-b-gate.md) | 30a7da42, 8872f852, 80f2d456, d9c5fabf, 796b31cd, 1b939d81, 02dcd0a7 (SHA `ccb3575b`) | G-B-01 **Met, with coverage limits** (7 of 16 cells not run) · G-B-02 Met · G-B-03 Met (lines 108–110) | **Formal review pending** (line 114) | G-B-11 coverage limits; run by the B2/B5 implementer (line 9) |
| 30 | C0 | [interaction-contract.md](../interaction-contract.md), [c0/prototype.html](../c0/prototype.html), [c0/scenario-check.md](../c0/scenario-check.md) | 26904680, 7d85f9b7, deb70f08, dfd2a45e | C0-01 Met (decided) · C0-02 Met (decided) · C0-03 **Limitation, accepted by product owner** — pilot only, results table empty (lines 205–207) | Formal review pending (line 6) | Real-user check moved to X2-R |
| 31 | C3-D | [approval-source-contract.md](../approval-source-contract.md) | 5a789af2 | C3-D-01 Met (proposed) · C3-D-02 Met (decided 2026-10-23) · C3-D-03 Met (decided) (lines 212–214) | Awaiting formal review (line 3) | Overtime/advance/amendment sources do not exist (§2.2) |
| 32 | C1 | [c1-implementation.md](../c1-implementation.md) | ef2e0785, dfd2a45e, 02dcd0a7 | Evidence (tests), no status label (lines 40–42) | Review pending (line 5); G-C "accepted as delivered" | English not walked in browser; notes out of date (G-C-15) |
| 33 | D1-D | [profile-contract.md](../profile-contract.md) | 432bfb9b | D1-D-01 Met · D1-D-02 Met (design) · D1-D-03 Met (design) (lines 159–161) | HR answers + TL approval of §5/§5.1/§7 (2026-10-27); QA review not recorded | — |
| 34 | C2 | [c2-implementation.md](../c2-implementation.md) | 291730af | Evidence (tests), no status label (lines 36–38) | Review pending | Contracts not searchable as records (§6) |
| 35 | C3 | [c3-implementation.md](../c3-implementation.md) | 14f32f88, c7428f75, 8fd4ae0f, a303000e, 99529cf3 | Evidence (tests), no status label (lines 43–45) | Review pending; **needs `php artisan migrate`** (line 5) | Leave-balance pages still read `tech_manager_id`; new-request notices open *All leaves* |
| 36 | C5 | [c5-implementation.md](../c5-implementation.md) | a2ca4897, c08933a7; **0613818d (post-gate redesign, not in any doc)** | Evidence (tests), no status label (lines 43–45) | Review pending; TL review of contract §4/§6 pending | Redesign + holiday editing in 0613818d is after G-C and unreviewed |
| 37 | D1 | [d1-implementation.md](../d1-implementation.md) | 695b96f9 | Evidence (tests + browser), no status label (lines 35–37) | Review pending (line 5) | Edit/delete of existing entries not in this version; English, rejection and photo proposal not walked |
| 38 | D3 | [d3-help-notes.md](../d3-help-notes.md), [d3-feedback-notes.md](../d3-feedback-notes.md), [evidence/d3](../d3) | 57ce03c7, 6b8b3e7c | Evidence (tests + browser), no status label. **Help copy PROPOSED, content review pending** (help line 6, 103) | QA/TL review pending | Content review questions 1–3 (help §5); Arabic guide out of date on contract types; no screen reader |
| 39 | C4 | [c4-implementation.md](../c4-implementation.md) | aa983edc | Evidence (tests), no status label (lines 38–40) | Review pending | Personalisation deferred |
| 40 | G-C | [g-c-gate.md](../g-c-gate.md), [evidence/g-c](../g-c) | b883f54b, 119a516f, 99529cf3, a303000e, 8fd4ae0f, 15d35620, 4b07f3e6, affdb274, 350fee96, 66c895b5, ba92ddd7, e7b5e237 (SHA `aa983edc` → re-run `4b07f3e6`) | G-C-01 **Met, with coverage limits** · G-C-02 **"Not met"** in the §5 table (line 88), but §7 (line 119) says **met** on re-run — **table never updated** · G-C-03 Met | **Formal review pending** (lines 96, 119) | Header still says "MATRIX RE-RUN PENDING" (line 7) although §7 records it; G-C-14, G-C-19 (N-06), G-C-20, G-C-21 open (low) |
| 41 | D2 | [d2-implementation.md](../d2-implementation.md) | 86c70911 | Evidence, no status label; D2-02 "real proof is the browser checks" (line 36) | Review pending | No real device / keyboard / screen reader (§5) |
| 42 | D2-PWA | [d2-pwa-implementation.md](../d2-pwa-implementation.md) | ade75db6, f56d320c | Evidence (tests + browser + Chrome installability), no status label (lines 23–25) | Review pending | Install not tried on a real phone; inline scripts blocked by CSP (pre-existing) |
| 43 | D4 | **No deliverable found.** No journey design doc in `docs/`. DEC-09 exists only as a one-line answer ([decisions.md](../decisions.md) line 139–140); [employee-lifecycle.md](../employee-lifecycle.md) is B5-D, not D4 | — | D4-01, D4-02, D4-03: **Not met** (no artifact) | — | Required by G-R dependency list (prompts line 141) |
| 44 | X1-I | [x1i-implementation.md](../x1i-implementation.md), [evidence/x1i](../x1i) | 1b5f4165, e6b2f46c, f7439998 | X1-I-01 Met · X1-I-02 **Met, with the limits in §7** · X1-I-03 Met (lines 165–167) | Awaiting (line 187) | §7 limits (see §4 below) |
| 45 | D4-I | **No deliverable found** (no doc, no commit tagged D4-I) | — | D4-I-01/02/03: **Not met / not started** | — | Blocked on D4 |
| 46 | X2-R | [x2-r-retest.md](../x2-r-retest.md) | 5e9d26b5 | X2-R-01 **Met by disclosure** · X2-R-02 **Not met** · X2-R-03 **Partly met** (lines 118–120) | Awaiting; "Not marked done" (line 6) | No participants; §6 register. Line 16 says no X1-I doc/after-run exists — **stale**: written at `7d340617`, before `f7439998` added it |
| 47 | G-R | this folder (in progress) | — | Pending | — | — |

**Out-of-plan work on the integrated SHA (no task ID, no doc, not gated):** T-1 holidays (a9e767fe, b4866561: project holidays, program-manager grants, birthday holiday, `LeaveRequestPricing::holidayDates` changed, two migrations); C5 calendar redesign (0613818d); 3dd78d76 (contract history *View* button, `@can('view')`-guarded); 4a6eb55f (search bar styling). T-1 and 0613818d change leave pricing inputs after every gate ran.

**Full suite at `81c5d28f`: not recorded anywhere.** Latest recorded run: X1-I, 2026-10-28, **1147 passed, 1 failed** (flaky `cities.slug` fixture, x1i-implementation.md line 134). Known flaky: `ContractsTableB4Test` "no amount in HTML" (`8731`) — a pay-privacy assertion that fails intermittently (g-b-gate.md lines 154, 222).

---

## 2. Requirements R-01 … R-24

Map from prompts lines 4122–4145. "Covered" = every listed task delivered with AC evidence (still unreviewed); "partial" = an AC open, a stated gap, or a pending human check; "not covered" = no deliverable.

| R | Requirement | Tasks | Status | Why |
|---|---|---|---|---|
| R-01 | Remove artificial waiting | P0, X1, A7 | Covered | A7 Met ×3 |
| R-02 | Navigate-safe transitions | P0, X1, B1 | **Partial** | B1-02/03 open (no browser evidence in B1 docs); G-B checked back-navigation only for the employee table |
| R-03 | Feedback and work preservation | P0, B0, A5-M, B2 | Partial | A5-M-06 not browser-tested; screen-reader pass outstanding |
| R-04 | Leave impact and request clarity | A3-R, A3-D, A3 | Partial | A3 ACs pass; open policy items F11, D-7 double approval, forged `type=hour`; T-1 changed holiday pricing after G-A/G-B |
| R-05 | Loading/empty/retry | B0, B3 | Partial | B3 browser checks pending, not reviewed |
| R-06 | Task/role navigation | C0, C1 | Covered | C0-03 by accepted limitation (pilot only) |
| R-07 | Bilingual search | C0, C2 | Covered | Contracts not searchable as records |
| R-08 | Quick actions | C0, C2 | Covered | — |
| R-09 | Unified approvals | C0, C3-D, C3 | Partial | Built for leave, checklist, profile changes; DEC-05 sources overtime/advances/amendments do not exist; migration not run |
| R-10 | Role dashboards | C0, C4 | Covered | — |
| R-11 | Unified profile | D1-D, D1 | Covered | Editing existing entries deferred |
| R-12 | Team calendar/coverage | A3-D, C5-D, C5 | Partial | C5-D TL review pending; post-gate redesign 0613818d and T-1 unreviewed |
| R-13 | Mobile self-service | M1, D2, D2-PWA | Partial | No real device, keyboard or screen reader anywhere |
| R-14 | Contextual help | D3 | **Partial** | Help copy proposed, content review pending |
| R-15 | Measured usability | X2, X2-I, X2-R | **Not met** | 0 participants before or after; telemetry off; X2-R-02 Not met |
| R-16 | Compact tables/views | B4-D, B4 | Covered | Saved-views migration not run |
| R-17 | Resumable onboarding | B5-D, B5, M1 | Partial | DEC-04 autosave not built; live migration not run |
| R-18 | EN/AR localization | A4, A5-M | **Partial** | A4 Arabic content review pending (doc: "not done") |
| R-19 | Accessibility | A5, B0, A5-M, M1 | **Partial** | Screen-reader pass never run (A5 doc: "not done"); G-C-21 composer radios/comboboxes still misnamed |
| R-20 | Non-duplicate notifications | B6 | Partial | Tests only; browser pending; not reviewed |
| R-21 | Lifecycle journeys | D4, D4-I | **Not covered** | No D4 or D4-I deliverable |
| R-22 | Contract/PDF | A6-D, A6 | **Partial** | A6-01 Open; A6-D-02 real path not exercised; owner attestation only |
| R-23 | Performance/recovery | X1, X1-I | Covered with limits | X1-I-02 Met with §7 limits |
| R-24 | Access and digital ID | P0, A1, A2-D, A2 | Partial | A2 Met (tests); G-A #4 (13 coarse-permission delete/toggle actions) unresolved; no A2 browser walkthrough |

---

## 3. Optional work: selected or deferred

| Item | Decision | Status |
|---|---|---|
| D2-PWA | **Selected**, DEC-07 (decisions.md lines 129–130): installable, no offline caching of private data | Built (ade75db6), review pending; AC-D2-PWA-01 cites DEC-07 |
| X2-I | **Selected**, DEC-10 (lines 142–143): anonymous, own server, 90-day deletion | Built (42daa58f); **collection OFF** by default (X2I-Q3); X2I-Q1/Q2/Q4 on implemented defaults, not decided |
| D4-I | DEC-09 (lines 139–140) **selects** the first slice: onboarding draft → contract → account → ID card → orientation | **Neither built nor formally deferred.** D4 (design) has no deliverable, so AC-D4-03 ("which parts are designed, selected or deferred") has no answer. B5 covers draft → contract → account only; ID card and orientation are not linked as a journey. For AC-G-R-02 this must be declared deferred with an owner decision, or delivered |

---

## 4. Open findings (critical / high / medium, unresolved)

Flags: **[ACC]** access, **[LEAVE]** accounting/leave balances, **[DATA]** destructive data or duplicate writes, **[CORE]** core task. "Unrated" = the source gives no severity; included because it carries a flag.

| # | Finding | Severity (source) | Flags | Source | State |
|---|---|---|---|---|---|
| F-1 | No usability baseline and no re-test; every user-level claim unmeasured | **High** | [CORE] | x2-r-retest.md line 91 (R-1); usability-baseline.md §6 | Open |
| F-2 | Task D end state and many A/B/C AR/phone cells unwalked; HMS HR in EN, general manager, super-admin not covered | **High** | [CORE] | x2-r-retest.md line 92 (R-2); g-c-gate.md lines 37, 117 | Open |
| F-3 | D4 and D4-I have no deliverable although D4 is a G-R prerequisite and DEC-09 selected a slice | High (traceability) | [CORE] | prompts line 141; §1 rows 43, 45 | Open |
| F-4 | 13 reference-table `delete()` / `toggleItemStatusConfirmed()` actions rely only on the coarse permission (no city/record check) | Medium | **[ACC] [DATA]** | g-a-gate.md line 56 (#4); feedback-contract.md line 105; b6-implementation.md line 55 | Open, "needs a task" — none found |
| F-5 | Screen-reader pass (NVDA/VoiceOver, EN+AR) for A5, A5-M, A4, B0 (and B3, B6, D2, D3) | Medium | [CORE] | g-a-gate.md line 54 (#2); g-b-gate.md line 91 (G-B-11); a5-implementation.md line 4 | Open |
| F-6 | Maternity across December blocked by D-7 unless flagged; HR answer pending | Medium | **[LEAVE]** | g-a-gate.md line 55 (#3); a3-implementation.md line 119 | Open |
| F-7 | December "exceptional" needs manager **and** HR approval (D-7), "not built (C3)"; C3 doc and DEC-05 rev 2 (only the direct manager decides) never address it | Unrated (A3 §4 says not built) | **[LEAVE]** | a3-implementation.md lines 113, 124, 185; decisions.md lines 36, 85 | Open; policy conflict undocumented |
| F-8 | Forged `type=hour` accepted server-side on a non-annual balance | Low-medium | **[LEAVE]** | g-a-gate.md line 59 (#7); a3-implementation.md line 123 | Open |
| F-9 | AC-A6-01: no real Arabic/English PDF render evidenced; A6-D-02 real path not exercised; G-A relies on product-owner statement | Open AC | [CORE] | a6-implementation.md lines 4, 27; contract-failure-triage.md line 22; g-a-gate.md line 40 | Open (user-attested only) |
| F-10 | X1-I §7: operation keys live in cache for 1 day — after a cache clear, a retried contract-create/renew token could **write again**; contract create not in one transaction (Check result can say "not saved" when the contract exists); two renew dialogs carry different tokens | Unrated ("Met, with the limits in §7") | **[DATA] [CORE]** | x1i-implementation.md lines 171–173 (§7) | Open; unique operation-key column not added |
| F-11 | T-1 holiday reshape (a9e767fe) and C5 redesign (0613818d) change `LeaveRequestPricing` holiday inputs after G-A/G-B/G-C; the migration drops `holidayable`/`name` and converts rows; a converted city holiday with no project "applies to nobody", so days previously excluded may now be **deducted**; no task doc, no gate | Unrated (found here) | **[LEAVE] [DATA]** | migration `2026_10_28_100000_reshape_holidays_for_projects.php` lines 13–23, 94–100; commit b4866561 (failed on MySQL prefix before fix) | Needs review before release |
| F-12 | Help copy (leave rules, auto-renew, contract types) PROPOSED, content review pending; Arabic guide out of date | Medium | [CORE] [LEAVE] | x2-r-retest.md line 97 (R-7); d3-help-notes.md lines 6, 103, review questions §5 | Open |
| F-13 | A4 Arabic content review pending; A4 "not done" | Process (blocks A4) | [CORE] | a4-implementation.md line 4, line 174 | Open |
| F-14 | Leave Cancel was a reloading link → telemetry reads abandoned | Medium | [CORE] | x2-r-retest.md line 93 (R-3) | Open (verify vs A5-M) |
| F-15 | Arabic leave-type revert / list reopens after keyboard selection (G-C-14 D-09) | Medium (R-4) / Low (G-C-14) | [CORE] | x2-r-retest.md line 94; g-c-gate.md line 76 | D-09 "needs correction" |
| F-16 | Composer a11y: radios named "1/5/3", comboboxes «اختيار» (G-C-21 / R-9) | Medium (R-9) / Low (G-C-21) | [CORE] | x2-r-retest.md line 99; g-c-gate.md line 114 | Open |
| F-17 | `/employees` renders its table twice; X1-I change not re-measured for that flow (harness search persisted) | Medium | [CORE] | x2-r-retest.md line 100 (R-10); x1i-implementation.md line 125 | Open |
| F-18 | Arabic SUS/SEQ unvalidated | Medium | — | x2-r-retest.md line 98 (R-8) | Open |
| F-19 | Formal reviews pending for G-A prerequisites, G-B, G-C, C-series, D-series, X-series | Medium (R-13) / Process | all | x2-r-retest.md line 103; g-a-gate.md line 61 (#9) | Open |
| F-20 | No CI | Process | — | g-a-gate.md line 62 (#10); start-state.md §6a | Accepted limitation |
| F-21 | G-C gate document is internally inconsistent: header "MATRIX RE-RUN PENDING" and §5 AC-G-C-02 "Not met" vs §7 "met" | Process | [ACC] | g-c-gate.md lines 7, 88, 119 | Fix document before citing |
| F-22 | Pay-privacy test flaky (`ContractsTableB4Test`, `8731` in HTML) | Unrated | [ACC] | g-b-gate.md lines 154, 222 | Open |
| F-23 | Employees Excel export has no server-side permission check in the component (pre-existing) | Unrated | **[ACC]** | b4-implementation.md line 30 | Open, unverified here |
| F-24 | A2 open points: birthdays (private tier) shown to all staff; city HR hotline editable without city scope; program/center lists show former employees | Unrated | **[ACC]** | a2-implementation.md lines 81–87 (§5 points 3–5) | Open (decisions needed) |
| F-25 | Service-agreement absence (manager-approved, no deduction) has no home | Unrated | [LEAVE] | a3-implementation.md line 121; decisions.md line 39 | Open |
| F-26 | Two "remaining" bases on one leave screen (accrued vs full entitlement) | Observation | [LEAVE] | evidence/g-c/browser-rerun.md §8 observation (a) | Open |
| F-27 | browserless.io: old token in git history; rotation user-attested (not verified by any gate) | Was critical (A1 §6) | [ACC] | test-baseline.md §6, §9; start-state.md §8; g-a-gate.md line 40 | User-attested closed |

Closed and verified in docs (not carried): G-C-1 and G-C-2 (High, fixed and re-run, g-c-gate.md lines 63–64, 102); G-B-1…G-B-10, G-B-13 (g-b-gate.md §9); G-A #1 (b2-implementation.md §2); A1 critical cross-city drawer (A2 tests, G-A §1).

Low items still open (listed for completeness, not blocking by rule): G-C-14 D-10 (`/centers` 500 for a manager on SQLite), G-C-13 F11, G-C-19 N-06, G-C-20 (N-04), X1-I tour-card CLS, G-A #5, #6, #8.

---

## 5. Decisions DEC-01 … DEC-10

| DEC | Status | Record | Delivery state |
|---|---|---|---|
| DEC-01 | Settled 2026-10-16; detailed in A2-D, approved 2026-10-20 | decisions.md lines 92–98; access-policy.md §9 | A2 built; A2 §5 points 3–5 open |
| DEC-02 | Settled 2026-10-16; revised 2026-10-20 (readable link, accepted guessable-link risk) | lines 100–108 | A2 built; no cards printed yet |
| DEC-03 | Settled 2026-10-16; follow-ups D-1…D-9 2026-10-20; D-4 refinement 2026-10-21 | lines 5–48 | A3 built; F11, D-7 double approval, service-agreement absence open; holidays reworked by T-1 (out of plan) |
| DEC-04 | Settled 2026-10-16; B5-D answers 2026-10-20 | lines 50–56 | **Partly delivered**: "automatic draft" (autosave) not built (b5 §6 #3) |
| DEC-05 | Settled 2026-10-16; leave revision 2026-10-20; revision 2 2026-10-27 | lines 58–90 | C3 built for leave; overtime/advances/amendments not existing; D-7 dual approval unreconciled |
| DEC-06 | Settled 2026-10-16; detail 2026-10-21 | lines 110–127 | C5 built; TL review of C5-D §4/§6 pending |
| DEC-07 | Settled 2026-10-16: yes, no private offline cache | lines 129–130 | D2-PWA built |
| DEC-08 | Settled 2026-10-16: IT only, text only, 1-year retention | lines 132–137 | D3 feedback built (readers = IT contract holders) |
| DEC-09 | Settled 2026-10-16: onboarding journey first | lines 139–140 | **No D4 design, no D4-I** |
| DEC-10 | Settled 2026-10-16: anonymous, own server, 90 days | lines 142–143 | X2-I built, off; X2I-Q1…Q4 not settled |

---

## 6. Migrations introduced by plan tasks

No document records any migration run on the operational MySQL. Several were run on synthetic SQLite copies only.

| Migration | Task / commit | What doc says about running |
|---|---|---|
| `2026_10_20_000001_add_digital_id_card_index_to_employees_table` | A2 / dd33bdb1 | "no migration run on any real database" (a2 line 3); `down()` refuses while any index > 0 |
| `2026_10_20_000002_add_hr_hotline_to_cities_table` | A2 / dd33bdb1 | same |
| `2026_10_21_000000_add_locale_to_users_table` | A4 / 1a6d7d68 | "No live migration was run" (a4 §5); never roll back migration alone |
| `2026_10_21_000001_add_pricing_snapshot_to_leave_requests_table` | A3 / 5ad4cb90 | Deploy step only; run status not stated; do **not** run `down()` once snapshots exist (a3 line 144) |
| `2026_10_22_000001_create_task_attempts_table` | X2-I / 42daa58f | "Run on production only together with the deploy" — not stated as run |
| `2026_10_22_000002_create_notification_deliveries_table` | B6 / df99eeb9 | "Production: run `php artisan migrate`" — not stated as run |
| `2026_10_22_000003_create_saved_table_views_table` | B4 / 55ce0766 | "**has NOT been run**" |
| `2026_10_23_000001_add_record_status_to_employees_table` | B5 / ccb3575b | "**live migration is not run**"; pilot forward/back/forward on synthetic |
| `2026_10_27_000001_add_decision_basis_and_notes_to_leave_requests` | C3 / 14f32f88 | "Needs `php artisan migrate`" (also G-C-15) |
| `2026_10_27_120000_create_in_app_feedback_table` | D3 / 57ce03c7 | "not run on the operational MySQL" |
| `2026_10_28_000001_create_profile_change_requests_table` | D1 / 695b96f9 | TL-approved 2026-10-27; run on synthetic copy only; operational status not stated |
| `2026_10_28_100000_reshape_holidays_for_projects` | **T-1 (out of plan)** / a9e767fe, b4866561 | **No doc.** Converts and drops columns; `down()` throws once project/grant/birthday rows exist; commit says it failed on the MySQL prefix before the fix |
| `2026_10_28_100100_create_birthday_holiday_choices_table` | T-1 / a9e767fe | No doc |

Pre-plan (not a task): `2026_10_05_000000_disable_auto_renew_on_terminated_contracts` (8e56a2b6).

---

## 7. Reading for AC-G-R-01 / AC-G-R-02

- **AC-G-R-01 (no unresolved critical/high access, accounting, destructive-data or core-task regression):** no critical/high *defect* is open in a gate document (G-C-1/2 fixed and re-run). It cannot be confirmed, though: F-4 [ACC][DATA], F-10 [DATA], F-11 [LEAVE][DATA] and F-7 [LEAVE] are unresolved and unrated, the post-gate T-1/C5 changes were never gated, and no full suite has been recorded at `81c5d28f`.
- **AC-G-R-02 (all selected scope has acceptance evidence; deferred work explicit):** **not met.** D4/D4-I have no deliverable or recorded deferral; A6-01 is Open; X2-R-02 is Not met (0 participants); B1-02/03 have no browser evidence; A4 and A5 say "not done" themselves; and 43 of 47 tasks have no recorded review.
