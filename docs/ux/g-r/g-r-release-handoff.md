# G-R: Integrated regression and release handoff

Task G-R in [ux-prompts-v3.md](../ux-prompts-v3.md) (R-01 to R-24). Version 1.0, 2026-10-29.
Owner role: QA lead + technical lead. Reviewer: QA engineer + technical lead. **Status: delivered, awaiting review. Not marked done.**

**Integrated SHA: `207f31c2`** (branch `g-r` = `81c5d28f` + merge of `hr-ux-by-rafi` commit `4d273468`, which changed 6 files and no migration, during this gate). Checks that ran on `81c5d28f` are marked; §2 explains why they still hold.

G-R changed no product code, schema or data. Its only repository changes are the files in `docs/ux/g-r/`. Raw evidence (screenshots, junit, logs) is in `docs/ux/evidence/g-r/`, which is **git-ignored** (`.gitignore:33`), so it exists only on this machine; §8 says how to regenerate it.

**Independence:** the checks were run by AI agents in the same workspace that built the features, not by an independent QA team. Treat them as a first pass for the reviewers.

---

## 1. Recommendation

**Do not mark the v3 UX plan released yet. The code at `207f31c2` is technically fit for a staged rollout under the conditions in §6.**

- **Technical health is good.** The full suite passes, the build and Pint pass, migrations round-trip, a real PDF renders, and the browser matrix found no access, salary or duplicate-write failure (§2 to §4).
- **AC-G-R-02 is not met, so the release record is incomplete:**
  - D4 (journey design) has **no deliverable**, and D4-I is neither built nor formally deferred.
  - X2-R has **0 participants**, so no usability improvement can be claimed.
  - Only 4 of 47 tasks have a recorded review sign-off.
  - A4 (Arabic content review), A5 (screen-reader pass) and the D3 help text are still open.
- **AC-G-R-01** is met for what was tested: **no critical or high issue is open.** The medium items in §5 are open, and three of them touch leave balances.

Deployment, live migration, publishing and any message to staff remain separate decisions for the user. This document does not authorize them.

## 2. Automated checks

| Check | SHA | Result | Evidence (`docs/ux/evidence/g-r/runtime/`) |
|---|---|---|---|
| Full suite (PHP 8.5.11, SQLite `:memory:`) | `81c5d28f` (pinned worktree) | **1148 tests, 7003 assertions, 0 failures, 0 errors, 0 skipped**, 487 s | `suite-81c5d28f-*` |
| Full suite | `207f31c2` | **1148 passed, 6999 assertions, 0 failures**, 426 s, exit 0 | `suite-207f31c2-*` |
| Earlier run that was voided | mixed | 37 failures caused by the merge landing mid-run (half-updated view, "Undefined array key `returns`"). The 12 failing classes then passed on `207f31c2`: 212 passed, 0 failed. | `contaminated-run-NOTE.txt`, `rerun-failed-classes-on-207f31c2.txt` |
| `npm run build` (Vite 8.3.0) | `207f31c2` | Pass, 342 modules, no warnings | `npm-build.txt` |
| `pint --test` on the 325 PHP files changed since baseline `0d7cd8d7` | both | 0 failing files (Blade is not covered by Pint) | `pint-test-*.txt` |
| `salary:key:check` (read-only; scratch SQLite) | `207f31c2` | 1/1 readable. **Says nothing about production**: run it there after deploy. | `salary-key-check.txt` |

Earlier suite counts for comparison: G-B 930, G-C 1004 then 1060, D2 1026, X1-I 1147 + 1 flaky failure. The flaky tests (`cities.slug`, `ContractsTableB4Test`) passed in this run.

## 3. Browser matrix (synthetic data)

Run on a fresh synthetic SQLite copy (`C:/laragon/tmp/hc-hr-gr`, PHP 8.5, port 8406, production mode, Playwright Chromium), **at `81c5d28f`**. The merge after it changes navigation and two leave modules. Their test classes pass, but **the browser matrix was not re-run on `207f31c2`.** Script: [gr_browser_check.py](gr_browser_check.py) with [common.py](common.py). Results: `docs/ux/evidence/g-r/browser/results.json`, 75 screenshots, and `db-after.json`.

**79 checks: 78 passed, 1 low defect.**

| Area | Result |
|---|---|
| Login → home: 5 roles × EN/AR × 1440×900 and 375×812, plus AR 812×375 | Pass. AR is `dir=rtl lang=ar`, there is no sideways scroll, and phones show the bottom nav. |
| Leave request, EN desktop and AR phone | Pass |
| Double and triple click on confirm Save | Pass: one row per date |
| Manager decides in the inbox | Pass |
| Two sessions decide the same request (accept vs reject) | Pass: one decision write. The second user sees "already accepted by … Your decision was not saved." Activity log: created + 1 update. |
| HR All leaves (HR-ALP, HR-HMS, EN/AR); employee gets 403 | Pass |
| D1 profile and self-edit proposal; D3 help and feedback; D2 phone nav; team calendar | Pass |
| Global search (5 role/term pairs) | Pass. No former employee from another city appears. Active employees from other cities do appear: this is the `DirectoryAccess` directory tier (DEC-01), not a leak. |
| 16 cross-city/role direct-URL probes | Pass: all return 403/404 and show nothing |
| Another employee's profile (4 cases) | Pass: no national number, personal phone or pay |
| Salary-blind super-admin: 11 pages, 34 responses incl. 10 Livewire updates | Pass: no salary value found anywhere. Positive control: HR-ALP does see the figure. |
| Contract PDF | HR-ALP: 200, valid 229,486-byte `%PDF-1.4` (sha256 `257ba02c…0b49`; the file stays outside the repo). Super-admin: 403. HR-ALP on an HMS contract: 403. |

**Defect GR-1 (Low):** the inline `<script>window.CustomScrollbarConfig…` at `resources/views/components/layouts/app.blade.php:102` has no CSP nonce, so the browser blocks it on every authenticated page. The console shows one error per page, and the scrollbar falls back to its defaults. The live site probably shows the same error; it was not checked there. **Fixed after the gate (2026-10-29):** the script now carries `nonce="{{ Vite::cspNonce() }}"`, like `partials/head.blade.php`. Re-checked as HR-ALP on `/en/` and `/ar/`: 0 console errors, and `window.CustomScrollbarConfig` is defined. It was the only inline `<script>` without a nonce in `resources/views`.

**Not covered:**
- Browser re-run on `207f31c2`.
- HR deciding a profile-change request, or deciding on behalf of an absent manager.
- A manager deciding in AR or on a phone.
- HR-SYR, the general manager and HR of DMS/LAT/TAR.
- MySQL, Firefox/Safari, real phones, screen reader, 200 % zoom.
- Server-side protection against a repeated save (the dialog closes after the first click, so the browser never sends a second save).
- Holiday screens and the birthday holiday (T-1).

## 4. Migrations, PDF and data

**13 migrations since baseline** (details in `migrations-roundtrip.txt` and [traceability.md §6](traceability.md)):
- **11 are additive** with a matching `down()` and no data backfill.
- **`digital_id_card_index`:** additive, but `down()` keeps the column once any card was rotated (by design).
- **`2026_10_28_100000_reshape_holidays_for_projects`: destructive conversion.** It drops `holidayable_*` and `name`, then writes `scope` and `holiday_project` links. `HolidayMigrationTest` covers the conversion with data and the refusing `down()`.

**Round trip** on a scratch SQLite file: `migrate:fresh`, then `rollback --step=13`, then `migrate`. All exited 0. The tables were empty; the holiday conversion with data is covered by the test above, not by this round trip.

**No migration from this plan is recorded as run on the operational MySQL.** B4, B5 and D3 say so explicitly; the others are silent.

**PDF:**
- `contracts:export-doctor` on scratch SQLite with local Chrome: exit 0, 228,932 bytes. Its `chrome --version` "FAIL" is the known Windows behaviour and does not count.
- Renderer directly: a 5-page PDF of 227,801 bytes.
- Production renders through browserless (`BROWSERSHOT_WS_ENDPOINT`), which was **not** exercised here. The user attested on 2026-10-20 that production export works. That is the only evidence for production, so run the doctor there after deploying.

## 5. Open items (AC-G-R-01)

**No critical or high issue is open in any gate or task doc.** G-C-1 and G-C-2 were fixed and re-run. Full list with sources: [traceability.md §4](traceability.md).

| # | Item | Sev. | Area | Disposition proposed |
|---|---|---|---|---|
| O-1 | T-1 project holidays (`a9e767fe`, `b4866561`) and the C5 redesign (`0613818d`) change `LeaveRequestPricing` holiday reading but were never gated. A former city holiday whose city has no grade-3 project becomes "applies to nobody", so **new quotes may deduct days that used to be excluded.** Approved requests are not rewritten. | Medium | Leave balances | **Release condition:** before migrating production, list the city/program holidays that would get 0 links (query in §6), and have HR assign projects right after. |
| O-2 | D-7 says exceptional December leave needs manager **and** HR approval. A3 left it to C3, and C3 does not implement it. | Medium | Leave balances / policy | HR product owner to confirm the rule is still wanted, then a task. |
| O-3 | Maternity leave across December still waits for an HR answer. The server is also reported to accept a forged `type=hour` on non-annual balances (not re-verified by G-R). | Medium | Leave balances | Verify the server rule, then a task. |
| O-4 | 13 reference-table delete/toggle actions check only the coarse permission (G-A #4). | Medium | Access | A task. All 13 are HR/admin-only screens. |
| O-5 | If the cache is cleared between attempts, a retried contract create/renew can write twice. Contract create is not in one transaction (X1-I §7). | Medium | Data | Accepted limit for now; do not clear the cache during working hours. |
| O-6 | GR-1: inline script blocked by CSP | Low | UI | **Fixed** (nonce added; §3) |
| O-7 | Stale doc headers: g-c-gate header and §5, x2-r-retest note on X1-I, the "uncommitted" headers in A3-R, B3 and B4, and B2's `national_number` index claim | Low | Docs | Correct during review. Check the index on MySQL with `SHOW INDEX FROM hchr_employees`. |

## 6. Rollout and recovery

**The rollback of code does not undo the holiday migration.** Its `down()` refuses once project/grant/birthday rows exist and cannot restore `name_ar` or project links. Data recovery is **restore from backup**, not `migrate:rollback`.

**Before deploying** (HR + technical lead, production):
1. Take a full database backup with `mysqldump`, plus a separate dump of `hchr_holidays`. **Prove the restore** by loading it into a scratch database and comparing row counts. This proof has not been done; it is needed for AC-G-R-03.
2. List the holidays that will be orphaned:
   ```sql
   SELECT h.id, h.name, h.holidayable_type, h.holidayable_id FROM hchr_holidays h
   WHERE h.holidayable_type LIKE '%City' AND h.holidayable_id <> (SELECT id FROM hchr_cities WHERE slug='SYR');
   ```
   Then check which of those have a project in their city. Send the list to HR.
3. Note the current commit on the server, so the code can be returned to it.

**Deploy:** the release procedure in [SECURITY_PRODUCTION_RUNBOOK.md](../../SECURITY_PRODUCTION_RUNBOOK.md#release-procedure) (`migrate --force`, `optimize`, `queue:restart`).

**After deploying:**
- `php artisan salary:key:check`, then `php artisan contracts:export-doctor`.
- Open one page per role, and export one PDF.
- HR assigns projects to the holidays from step 2.
- The scheduler needs `leaves:send-decision-reminders`, which is idempotent.

**If something breaks:**

| Situation | Action |
|---|---|
| A UI or logic bug, no data harm | Forward fix, or return the code to the noted commit. The additive columns can stay (old code ignores them). **Do not run `migrate:rollback`.** |
| Wrong leave deductions from holidays | Fix the holiday links in the Holidays screen. Requests already approved keep their snapshot (A3 pricing snapshot), so re-check only those created after the deploy. |
| Holiday migration failed halfway (MySQL DDL is not transactional) | Restore `hchr_holidays` from the step-1 dump, return the code to the noted commit, fix, and migrate again. |
| Salary figures unreadable | `salary:key:check`; restore the correct `SALARY_ENCRYPTION_KEY`. Never re-save contracts until it reads OK (`EncryptedSalary` refuses to overwrite, by design). |

Revoked credentials and rotated keys are not restored by any rollback (see [SECRET_ROTATION.md](../../SECRET_ROTATION.md)).

## 7. Scope: selected, deferred, untested (AC-G-R-02)

| Item | State |
|---|---|
| D2-PWA | Selected (DEC-07), built, review pending |
| X2-I | Selected (DEC-10), built, **collection OFF**; Q1–Q4 open |
| D4 | **No deliverable.** DEC-09 settled only the first slice (onboarding a volunteer, delivered as B5). |
| D4-I | **Not built, not formally deferred.** Proposed: defer to after release; a reason and an owner are needed from the HR product owner. |
| X2-R | Instrument ready; **0 participants**. No usability gain is claimed. |
| A4 / A5 / D3 help | Arabic content review, screen-reader pass and help-text review are pending. |
| Untested environments | MySQL (all automated runs use SQLite), production browserless PDF, non-Chromium browsers, real devices, assistive technology |

Per-task AC status: [traceability.md §1](traceability.md). Requirements R-01 to R-24: [§2](traceability.md).

## 8. Reproducing this gate (new developer)

1. `git checkout 207f31c2`, then `composer install` and `npm ci`, using PHP 8.5 (`C:/laragon/bin/php/php-8.5`).
2. `php artisan config:clear`, then `php artisan test --log-junit out.xml` (SQLite `:memory:`, about 8 minutes). Then `npm run build`, then `php vendor/bin/pint --test <files>`, with the files from `git diff --name-only 0d7cd8d7 HEAD -- '*.php'`.
3. Migration round trip: point `DB_CONNECTION=sqlite` and `DB_DATABASE` at a scratch file, then `migrate:fresh`, `migrate:rollback --step=13`, `migrate`. **Never against `.env`'s MySQL.**
4. Browser: build a synthetic home with the X2 builder ([x2/test-environment.md](../x2/test-environment.md)) at `X2_HOME=C:/laragon/tmp/hc-hr-gr`. Serve it with PHP 8.5 `-S 127.0.0.1:8406 -t public tests/Usability/Baseline/router.php` (`X2_PORT=8406`), then `python gr_browser_check.py` (Playwright Chromium). The script reads the fixture password from the manifest. Its sentinel salary and super-admin setup are described in its header.

## 9. HR/QA acceptance record

To be filled in by people. An AI run does not count as sign-off.

| Role | Name | Decision (accept / accept with conditions / reject) | Date | Notes |
|---|---|---|---|---|
| QA engineer | | | | |
| Technical lead | | | | |
| HR product owner (O-1 to O-3, D4-I deferral) | | | | |

## 10. AC status

| AC | Status | Evidence |
|---|---|---|
| **AC-G-R-01** No unresolved critical/high access, accounting, destructive-data or core-task regression | **Met for the tested scope.** Medium O-1 to O-5 are open; O-1 is a release condition. | §2 to §5 |
| **AC-G-R-02** All selected scope has acceptance evidence; deferred work and untested environments explicit | **Not met.** D4 has no deliverable, D4-I has no deferral, X2-R has no participants, A4/A5/D3 reviews are pending, and 43 of 47 tasks have no review. The deferred work and untested environments are listed. | §7, traceability.md |
| **AC-G-R-03** Reproducible handoff with recovery and an HR/QA acceptance record | **Partly met.** Reproduction (§8), recovery (§6) and a record template (§9) are here. The restore has **not** been proven, the record is unsigned, and the raw evidence is only on this machine. | §6, §8, §9 |
