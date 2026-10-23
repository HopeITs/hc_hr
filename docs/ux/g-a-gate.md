# G-A: Foundation milestone gate

Task G-A in [ux-prompts-v3.md](ux-prompts-v3.md) (R-01, R-03, R-04, R-18, R-19, R-22, R-24).
Integrated SHA: `8141fdc7ebd0167147bcda5d87a3bc0d13ff2aa4` (`main`, clean tree). Date: 2026-10-22.
Prerequisites: A2, A3, A4, A6, A7, B0, A5-M. No product code, data or schema was changed by G-A.

**Gate result: PASS WITH CONDITIONS.** No critical access, accounting or essential-workflow defect was found. The conditions are the open items in §5; none is waived silently.

## 1. Full suite (AC-G-A-01, AC-G-A-02)

`php artisan test --log-junit …` on PHP 8.5, SQLite `:memory:`, on the SHA above.

| Run | Tests | Assertions | Failures | Errors | Skipped |
|---|---|---|---|---|---|
| Baseline [test-baseline.md](test-baseline.md) §9 (`aceda68e`) | 552 | – | 9 | 0 | 0 |
| **G-A, `8141fdc7`** | **759** | **4304** | **0** | **0** | **0** |

All 9 baseline failures (access 5, contracts/PDF 3, duplicate expiry warning 1) now pass. No failure remains to classify.

## 2. Browser evidence (AC-G-A-02)

Chromium 151 via Playwright, `php -S` on 127.0.0.1, fresh synthetic SQLite (X1 fixtures), no `.env` change, no operational DB. Evidence and scripts are in the session scratchpad (`ga-browser/`), not committed.

| Check | Result |
|---|---|
| A5-M backdrop, dirty close, discard, Cancel without reload, Keep editing; leave dialog + HR departments dialog; EN/AR, desktop 1280×800 and phone 390×844 | First run 85/87. Both failures passed on 3 reruns (156/156): a cold-server login timeout and one Arabic focus-return race (see §5, item 1) |
| Save failure (AC-A5-M-06): validation error | Passed in 4 of 4 contexts: dialog open, entry kept, inline errors, guard still asks |
| Save failure: server answers 500 | Passed in 4 of 4: dialog and entry kept, Livewire error modal shown, Discard still works |
| Successful save, then reopen | Closes, shows result panel, reopens clean (4 of 4) |
| Phone 375×812 smoke, EN/LTR and AR/RTL, leave page | No horizontal overflow (also with dialog open), direction correct, Submit inside the viewport |
| Contract PDF button (HR, 10 checks) | Idle → busy (`aria-busy`, disabled, "Preparing PDF…" / "جارٍ تجهيز ملف PDF…") → 200 `application/pdf` → idle |

## 3. Area by area

| Area | Status | Evidence |
|---|---|---|
| Access (A2) | Pass | Suite green incl. `DirectoryAccessPolicyTest`, `DigitalIdCardPolicyTest`, `LivewireMutationCoverageTest`. No browser walkthrough of drawer/card (see §5, item 6) |
| Leave accounting (A3) | Pass | `LeaveRequestAccountingTest`, `LeaveRequestPricingTest`, `LeaveRequestSubmitFlowTest` green; A3 browser notes. Open rule: maternity across December (D-7) |
| Arabic selector (A3-R, A4) | Pass with condition | Tests green; A3-R is not committed as its own commit per its note, and the Arabic content review by a person is pending |
| PDF output (A6) | **User-attested** | The product owner states export works on the production server and that the browserless key was rotated on the server (2026-10-22). Not independently verified by G-A; no local render is required. The 503/retry path is covered by tests and the button state by browser |
| Shared feedback (B0) | Pass with condition | Suite green. The only toast evidence in this run is weak (see §5, item 5) |
| Keyboard dialogs (A5, A5-M) | Pass with condition | Section 2. Screen-reader pass not done |
| Loading (A7) | Pass | Suite green, A7 notes |

## 4. Critical-defect check (AC-G-A-01)

No test failure, browser failure or code spot-check showed cross-city exposure, salary exposure, wrong leave accounting or a broken essential workflow. Pay export is refused for `canViewSalary() === false` roles (spot-checked: `active_permission:contracts.export` and `Gate::authorize('export')`).

## 5. Open items and disposition (AC-G-A-03)

| # | Item | Severity | Disposition | Owner |
|---|---|---|---|---|
| 1 | Arabic: after a successful save, a clean Escape on the reopened dialog failed 3 times (ar-desktop ×2, ar-phone ×1) in a long scripted run. A short repro in AR and EN closed correctly, and EN passed. Also one focus-return race on ar-desktop. Cause not found. Effect if real: a stray "discard?" prompt; no data loss | Medium, unresolved | **Not accepted as explained.** Human check in AR (save, reopen, Escape). If it reproduces, fix before B2 reuses the guard. A single-threaded `php -S` probably explains the timing flakes | A5-M owner |
| 2 | Screen-reader pass (NVDA/VoiceOver, EN+AR) for A5, A5-M, A4, B0 | Medium | Cannot be done here. Accepted as a limitation until a person runs it; carry to G-B/G-R | QA lead |
| 3 | Maternity across December blocked by D-7 unless flagged; HR answer pending | Medium | Policy decision, not changed here | HR product owner |
| 4 | B0 §5.1: 13 reference-table delete/toggle actions rely on the coarse permission only (no city/record check) | Medium | Not a regression; not fixed by A2. Needs a task | Backend lead |
| 5 | No real toast text asserted; PDF export shows no toast, only a button label | Low | Accepted; wording check in B1/B2 | Frontend |
| 6 | No authenticated browser walkthrough of the A2 drawer, profile and card panel | Low | Accepted; reviewer to walk it on the synthetic copy in EN/AR | QA engineer |
| 7 | Forged `type=hour` accepted server-side on a non-annual balance | Low-medium | Tracked from A3 | Backend |
| 8 | Scroll-preservation check reads 0→0 | Low | Accepted (documented in A5-M) | Frontend |
| 9 | No recorded formal review of the seven prerequisites; A4 needs Arabic content review | Process | Reviews still required before marking each task done | Tech lead |
| 10 | No CI | Process | Carried from A1 | Tech lead |

## 6. Limits of this gate

Synthetic data only; one browser engine; no real phone or assistive technology; the PDF and key-rotation claims rest on the owner's statement.

## 7. Handoff

B1, B4-D and B6 may start. B6 should note item 4 and B2 must not reuse `GuardsModalClose` until item 1 is checked.
