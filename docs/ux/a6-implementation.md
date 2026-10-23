# A6: Contract export repair and the three-section composer

Task A6 (R-22) in [ux-prompts-v3.md](ux-prompts-v3.md). Prerequisite A6-D: [contract-failure-triage.md](contract-failure-triage.md).
Status: **implemented, awaiting review** (QA engineer + technical lead). AC-A6-01 (real printed pages) is **still open**; see §3. A6 is not done until that evidence exists.

## 1. What changed

| Area | Files | Change |
|---|---|---|
| Export failure | `ContractExportController` | A render that throws or returns a non-PDF answers `503` with a plain-text translated body, `no-store`, `Retry-After`. Logged with ids and a clipped reason only. Authorization stays outside the `try`, so a refusal is a 403. |
| Export-preview | `routes/employees-management.php`, controller | Previously skipped Gate/city checks (any user could read the pay clause locally). Now behind `active_permission:contracts.export`, 404 outside `local`, same policy and city check as the PDF. |
| Export UI | `contract/_export-pdf-state`, `_export-pdf-icon-button`, `contract-details`, `ContractsTable` | The link became an in-place fetch: loading state, translated error with Retry, toast. Retry repeats the same read-only GET, so it can never create a contract. Offered only when the user can export and `canViewSalary()`. |
| Composer guidance | `composer/_assignment`, `_termination`, `contracts.composer-help.*` (EN/AR) | Explains city, program and position dependencies, full-position capacity, draft versus active, and auto-renew. Sections, counter and summary are untouched. |
| Expiry warnings | `SendContractExpirationWarnings` | Same-day rerun no longer repeats the reminder (triage #4). |
| Tests | `ContractExportTest`, `ContractExportUiTest`, `ContractComposerGuidanceTest`, `ContractRenewalTest`, `ContractPayClauseTest`, `ContractCreatePerformanceTest` | Stale expectations rewritten (triage #1-#3). |

## 2. Decisions

- **Obsolete shaping not removed.** The dead `forPdf`/`ArabicText` branches stay (scope decision 2026-10-22), because the brief allows removal only after real Arabic export proof, which does not exist yet. They cannot fire today; a future caller passing `forPdf=true` would crash.
- `savePdf()` and `disableCaptureURLS()` are unchanged, so node stdout stays small.
- Salary: the export, the preview and the UI button all refuse roles where `canViewSalary()` is false (super-admin, general-manager, ...). No figure is placed in any payload for them.

## 3. Acceptance evidence

| AC | State | Evidence |
|---|---|---|
| AC-A6-01 readable Arabic/English PDFs | **Open** | No real render was run: production renders through browserless.io and no Chrome is available here. Run `php artisan contracts:export-doctor` on staging with synthetic annual and service contracts in EN and AR and inspect the printed pages. |
| AC-A6-02 unauthorized salary viewers | Covered by tests | `ContractExportTest`: other-city HR, super-admin, general-manager, missing permission and guest are refused before rendering; preview gated. |
| AC-A6-03 retry, regression checks | Covered by tests | 503 then 200 on the same contract, count and row unchanged; renewal and malformed query tests. |

Test status: the author's run (Windows, PHP 8.5) was `--filter="Contract|SalaryVisibility|Security"` with 2 failures (triage #3, #4), both fixed afterwards. **This session could not run the suite**: the sandbox has PHP 8.3 and Laravel 13 needs 8.5. Run `composer test` on PHP 8.5 before merging.

## 4. Handoff

Consumer: G-A. Open before closing A6: the real-render pass (AC-A6-01), the suite run on PHP 8.5, and review.
