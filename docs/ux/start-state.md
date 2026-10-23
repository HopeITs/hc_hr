# P0 · Start state, prerequisites and QA setup (proposed)

Task: P0 from [ux-prompts-v3.md](ux-prompts-v3.md) (requirements R-01, R-02, R-03, R-24).
Status: **approved 2026-10-20** (HR product owner + technical lead), with the limitations in §8.
Recorded: 2026-10-16. Discovery only: no product code, schema or data was changed.

Consumers: A1, A2-D, A3-D, B5-D.

---

## 1. Starting revision (AC-P0-01)

| Item | Value | Evidence |
|---|---|---|
| Checkout path | `C:/laragon/www/hc-hr` | SHARED RULES names `E:/hr/hc-hr`. That path belongs to another machine. The SHA below is what matters. |
| Branch | `main` | `git branch --show-current` |
| **Starting SHA** | **`882a126c3c61c517d71c08270804fd938e0a30ef`** | `git rev-parse HEAD` |
| origin/main | `882a126c3c61c517d71c08270804fd938e0a30ef` | `git fetch origin`, then `git rev-list --left-right --count HEAD...origin/main` gives `0 0` |
| Working tree | clean | `git status --porcelain` is empty |
| Reviewed baseline | `0d7cd8d70ae0bef17d90ae6902b9ebc711cadbd9` | Ancestor of HEAD (`merge-base --is-ancestor`). HEAD is 7 commits ahead. |
| Historical baseline | `origin/preserve-contracts-search` @ `7faa289a` | Ancestor of main. Main is 20 commits ahead, so nothing is left unmerged. |

### Reconciling `0d7cd8d7..882a126c` (7 commits)

| SHA | Subject | Effect on the plan |
|---|---|---|
| 36643761 | 016 · employee form tab showing previous contracts as a gallery | New UI surface in the employee form. B2/B5 should include it. |
| 0787400b | 017 · change AlternativeEmployee option for all job levels and roles | Affects the leave request flow. A3-D/A3-R must read the current rule. |
| fe818b6f, 656c69fe, 882a126c | merges of `hr-fixes-by-rafi` | none of their own |
| 3fb65cc7 | Serve web fonts through Vite as fingerprinted WOFF2 | Fonts now live in `resources/fonts/`. No theme change. |
| 007331d0 | update plan tasks | Puts the **v3 text into `docs/ux/ux-prompts-v2.md`** (see §6). |

None of the seven contradicts a SHARED RULES invariant. **Decision: 882a126c replaces 0d7cd8d7 as the starting SHA.**

### Runtime manifest

| Component | Version | Evidence |
|---|---|---|
| PHP (CLI + web) | 8.5.11 ZTS x64, `C:/laragon/bin/php/php-8.5/php.exe` | `php -v`. This is the only PHP on PATH. 8.1/8.3/8.4 are installed but not on PATH. |
| Composer | 2.10.3 | `composer --version` |
| Laravel | 13.33.0 | `php artisan --version` |
| Livewire / Volt / Flux | 4.4.6 / 1.11.2 / 2.20.0 | composer.lock |
| spatie/laravel-permission · medialibrary · browsershot | 6.25.0 · 11.23.8 · 5.4.0 | composer.lock |
| ramonrietdijk/livewire-tables · mcamara/laravel-localization | 6.5.4 · 2.4.2 | composer.lock |
| PHPUnit | 12.5.35 (no Pest, no Dusk) | composer.lock |
| Node / npm | 26.3.0 / 11.16.0 | `node -v`, `npm -v` |
| Vite · Tailwind | ^8.3.0 · ^4.3.3 | package.json |
| Browser automation | none in the repo (puppeteer ^25.11 exists only for Browsershot) | package.json |
| MySQL (dev) | 8.0.30 (binary only, no connection was made) | `mysql --version` |
| Git | 2.54.0.windows.1 | `git --version` |

### Test baseline on this SHA

The run was `config:clear` followed by `php artisan test --compact` on SQLite `:memory:`.

**Result: 544 tests: 531 passed, 13 failed. 2582 assertions, 195 s.**

SHARED RULES recorded 529 tests on 0d7cd8d7, with 516 passed and 13 failed. There are now 15 more tests, all passing, and the same 13 tests still fail. The failures are **not** accepted release exceptions. A1 owns them:

| # | Test | Area | Observed |
|---|---|---|---|
| 1 | ContractAutoRenewalWorkflowTest › warning command does not repeat same contract on same day | notification | expected 1, got 2 |
| 2 | ContractCreatePerformanceTest › composer renders all three sections… | contracts | `id="composer_center"` missing |
| 3 | ContractPayClauseTest › for pdf true does not throw… | contracts/PDF | `Class "App\Support\ArabicText" not found`. The class was deleted in 3a0e5c49, but 10 contract views still `use` it. |
| 4–7 | ContractPdfRendererTest (4 tests) | contracts/PDF | Browsershot `FileUrlNotAllowed`: the HTML string is treated as a URL |
| 8 | ContractRenewalTest › renew page ignores a malformed query string | contracts/security | `<script>` present in the output |
| 9 | LivewireMutationCoverageTest › every public livewire action is classified | access | 6 unclassified actions: `contracts-table::{confirmToggleAutoRenew,toggleAutoRenewConfirmed,updatedPaginators}`, `org-chart::toggleVacancies`, `position-{create,edit}::updatedFormCityId` |
| 10 | SecurityAuthorizationTest › digital id uses an unguessable token and hides inactive employees | access | expected 404, got 200 |
| 11 | Security\AuthorizationRegressionTest › profile drawer refuses an employee from another city | **city scope** | expected 403, got 200 |
| 12–13 | Security\PrivateAvatarAccessTest › disabled digital id / rotating the token | access | expected 200, got 404 |

The split is 5 access/security, 7 contracts/PDF and 1 notification, the same as the audit. Item 11 is a possible cross-city data exposure. It is the most urgent item for A1 and A2.

---

## 2. Prerequisite status (AC-P0-02)

Status values: **verified-present** = the code exists and an automated test passed on this SHA. **implemented-unverified** = the code exists but no test covers it. **partial**, **missing** and **unknown** have their plain meaning.

### The four commits named by P0

All four are ancestors of HEAD. Nothing was cherry-picked or rebuilt.

| Commit | Subject | Status |
|---|---|---|
| `87e31bfaddefb83fb2d50173ea28f9cd70663056` | Shorten the page loader instead of holding it for 1.5s | implemented-unverified. No test covers the loader timing, so A7 must measure it. |
| `8769e98a098d90dac88ccf70ffc90a3670a203c4` | wire:navigate from the sidebar | implemented-unverified. No browser test exists, so B1 verifies it. |
| `dd62fa259281fe343f9bfcfebe2555463432299d` | wire:navigate from in-page links | partial. ContractAccessByRoleTest was touched and passes, but navigation itself has no test. |
| `5feb02aa0638467ed42a43ed4067e87e109dffcb` | One toast and one dialog for results and deletes | verified-present on the server side (WithFeedbackTest and EmployeeEditSaveFeedbackTest pass). The browser side is unverified. |

### Component inventory

| Component | Paths | Status | Evidence / gap |
|---|---|---|---|
| WithFeedback trait | `app/Livewire/Concerns/WithFeedback.php` | verified-present | `toast()` and `confirm()`, covered by `tests/Feature/Livewire/WithFeedbackTest.php`. 50 components use it, with 70 `toast(` calls. Most of those components are not tested individually. |
| Toasts | `components/toasts.blade.php`, `resources/js/feedback.js`, `app/Livewire/Features/SupportToasts.php` | verified-present (server); JS implemented-unverified | Mounted at `components/layouts/app.blade.php:73` with `aria-live=polite`. Alpine timers, undo and pause-on-hover have no browser test. |
| Confirm dialog | `components/confirm-dialog.blade.php`, `Alpine.store('confirm')`, `$confirm` magic | verified-present (server); JS implemented-unverified | Mounted at `app.blade.php:74`. 32 server calls in 24 components, plus `$confirm(` in 8 views. Focus and return behaviour are unverified, which is input for A5. |
| Contracts search persistence | `app/Livewire/Contract/ContractsTable.php:39-42,134-144` | verified-present | `tests/Feature/ContractsSearchPersistenceTest.php` |
| LivewireTable subclasses (21) | `app/Livewire/**/*Table.php` | partial | 9 test files cover tables. Most of the Basics tables have no test. |
| Livewire Form objects (16) | `app/Livewire/Forms/**` | implemented-unverified | Exercised indirectly through component tests only |
| Shared Blade components | `resources/views/components/{forms,table,modal,tab,layouts,…}` | partial | `forms/input` and `field-caption` are covered by AccessibilityTest. `forms/dirty-gate` exists and is relevant to A5-M/B2. Flux is used lightly (26 tags). |
| Empty states | `table/no-data`, `center-empty-state`, `org-empty-node` | implemented-unverified | about 10 explicit uses and about 23 `@forelse`. No test. Input for B3. |
| Loading states | `wire:loading` (51 uses in 22 files), `layouts/app/page-loader` | implemented-unverified | No test. Input for A7/B3. |
| Flash → toast | `session('status')` → toast | verified-present | WithFeedbackTest. Only 4 `session()->flash` calls remain. |
| Sidebar / `@activerole` menu | `livewire/layout/sidebar.blade.php`, `AppServiceProvider.php:132,138` | implemented-unverified | Menu visibility is **not** authorization. Server checks are tested in `tests/Feature/Security/*`. |
| Locale switch / RTL | `layouts/app.blade.php:2`, `livewire/layout/header.blade.php:8-11` | partial | Translations are tested (CenterLocalizationTest, AccessibilityTest). The switch and `dir` have no test. Input for A4. |
| Services (12) | `app/Services/` | partial | 8 have direct tests. AccessRevoker, ActivityLogger and ProcessedImage have none. ContractAutoRenewalService is tested only indirectly. |
| Contract PDF pipeline | `ContractPdfRenderer`, `InspectableBrowsershot`, `pdf/contract.blade.php` | **partial / failing** | Failures 3–7 above, plus the missing `App\Support\ArabicText`. Input for A6-D. |
| Access classification | `config/access-control.php` | partial | 6 actions are unclassified (failure 9) |
| `docs/ux/ux-prompts-v3.md` | this folder | verified-present (copied by P0) | see §6 |
| Prior audit artifacts `main-audit-junit.xml`, `main-audit-tests.txt` | "beside the delivered plan folder" | **missing** | They are not in `Downloads/Telegram Desktop` or in the repo. §1 replaces them with a fresh run on this SHA. |

---

## 3. Test target and fixtures (AC-P0-03)

| Target | Status | Notes |
|---|---|---|
| Automated tests | **ready** | `phpunit.xml` uses `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, array cache/session/mail and sync queue. `tests/TestCase.php::createApplication()` **throws** if the testing DB is not SQLite `:memory:`, which guards against a cached config pointing at MySQL. |
| Synthetic fixtures | **ready** | `tests/Concerns/CreatesHrFixtures.php` (`makeCity`, `makeEmployee`, `makeUser(city, role, permissions)`, `assignInactiveRole`) and `tests/Concerns/CreatesContractFixtures.php` (`makePosition`, `makeContractFor`). Factories: Contract, Employee, User. Fixture roles hold **only** the permissions passed to `makeUser`. |
| Local browser QA | **needs a disposable database: decision pending** | `.claude/launch.json` has `hc-hr (php 8.5 serve)` on port 8385. The dev `.env` points at the **operational MySQL**, which is not disposable. A browser QA run needs its own DB, for example `hc_hr_qa` or a SQLite file, configured locally and not committed, then `migrate --seed` into it. |
| Seed data | **not synthetic: do not use for QA as is** | `EmployeeSeeder`, `CitySeeder` and others read `config/samples/sampleData.php`, which contains real-looking names, national numbers, phones and DOBs. A1 needs a synthetic seeder, or a decision on this file (privacy owner). `UserRoleSeeder` refuses to run outside local/testing. |
| Staging | **unknown / not available** | No staging URL is configured. `docs/SECURITY_PRODUCTION_RUNBOOK.md` has only the placeholder `https://hr.example.org`. Recorded as a limitation. |
| Real HR records | **out of bounds** | Not touched by P0, and must not be used as QA data. |

### Commands (from the P0-selected runtime)

```bash
php artisan config:clear
php artisan test --compact
php artisan test --filter=SomeTest
php vendor/bin/pint --dirty
npm run build
```

`php` on PATH already resolves to PHP 8.5.11. If PATH changes, call `C:/laragon/bin/php/php-8.5/php.exe` explicitly.

---

## 4. Integration and review contract (proposed)

- **Integration branch:** `main`, starting from `882a126c`. Each task branch is `codex/<task-id>-<slug>`, for example `codex/a1-test-baseline`, per SHARED RULES. No `codex/` branches exist yet.
- **Branch rule:** start from the latest `main` that already contains the task's prerequisite outputs. Rebase or merge `main` and rerun the dependent checks before marking a task done.
- **Roles:**
  - Implementer: the owner role named in the task.
  - Reviewer: a technical lead, plus QA for any task with ACs.
  - Policy tasks (`*-D`) also need the HR product owner and the security reviewer named in the DEC table.
  - Names and capacity are **not assigned**. This is pending for the technical lead.
- **Review procedure.** This replaces any slash-command skill another agent named. Those skills are not assumed to exist.
  1. Read the task in `ux-prompts-v3.md` and its SHARED RULES.
  2. Diff against the starting SHA and confirm the scope.
  3. Run the full suite and record the before/after counts and failures against the §1 baseline. A new failure blocks the task.
  4. Check every authorization surface: active role, city (`Position::scopeInCity`), record, and `User::canViewSalary()`. No figures may appear in HTML, Livewire payloads or PDFs.
  5. Check EN/AR and RTL, plus the viewports from SHARED RULES, when the UI changed.
  6. Map each AC to its evidence.
  7. Note limitations and the next consumer inputs.
- **CI caveat:** `.github/workflows/security.yml` pins **PHP 8.3 and Node 20**, while composer requires `php ^8.5`. The test job therefore cannot reflect this baseline. Gitleaks may also flag `config/samples/sampleData.php`. A1 owns this; P0 does not change CI.
- **Docs caveat:** `AGENTS.md` still says Laravel 12, Livewire 3 and PHP 8.4/8.3. `CLAUDE.md` and this file are the current sources.

---

## 5. Open items handed on

| Item | Owner | Blocks |
|---|---|---|
| The 13 failing tests, starting with the cross-city profile drawer (failure 11) | A1 → A2 | G-A |
| `App\Support\ArabicText` missing from 10 contract views | A1 / A6-D | A6 |
| A disposable QA database, and a decision on `config/samples/sampleData.php` | Technical lead + privacy owner | browser evidence for X1, X2 and A5 |
| CI runtime mismatch (8.3/20 vs ^8.5/26). Superseded by §6a: there is no CI at all (the remote is GitLab and the repo has no `.gitlab-ci.yml`) | Technical lead (decision) | trustworthy CI |
| Hard-coded browserless.io token, with contract and pay pages sent off-host (§6a) | Security reviewer + A6-D | A6, release |
| Assign implementer and reviewer names | Technical lead | none (planning only) |
| DEC-01 … DEC-10 | per the plan's table | the listed dependents only |

## 6. Plan document location

- `docs/ux/ux-prompts-v3.md` is the declared master. It was copied in by P0 from the delivered file, `ux-prompts-v3.md` (254,971 bytes, 4165 lines).
- Commit 007331d0 had already put the same v3 text into `docs/ux/ux-prompts-v2.md`. With CRLF ignored, the two differ only on the last line: the delivered file ends with a malformed `<div>` instead of `</div>`.
- The repo copy uses the committed LF text with the correct closing tag. Its sha256 is `8f7744967c9bf7dc301f8ff93e0ea386cdf97bd2704d294b1921be7049b727f9`.
- `ux-prompts-v2.md` was left untouched, because renaming or restoring it is a separate decision.

## 6a. Reconciliation addendum (2026-10-16, during A1)

- **HEAD moved to `e9c89b8dc97ce4d7402adb2f236a47c87bd13b24`** ("add updated plan"). `origin/main` is the same commit (`git fetch`, then left-right count `0 0`). Its only change is adding `docs/ux/ux-prompts-v3.md` (+4166 lines), so §6's copy is now committed. No code, test or config changed, and the §1 baseline still holds: A1 reran it on `e9c89b8d` and got 544 tests, 531 passed and the same 13 failing (see [test-baseline.md](test-baseline.md)). **Starting SHA for downstream tasks: `e9c89b8d`.**
- **§3, guard wording.** Before A1, the guard in `tests/TestCase.php` ran only when `APP_ENV` was already `testing`. An inherited `APP_ENV=local` skipped it, and `phpunit.xml` did not override shell values. A1 made the guard unconditional and pinned `APP_ENV`, the locale and `DB_*` through `<server>` and `<env force>`. See test-baseline §2.
- **§4, CI caveat.** The remote is **GitLab** (`gitlab.com:hope2025/hc-hr`), and the repo has no `.gitlab-ci.yml`. `.github/workflows/security.yml` therefore never runs, so the PHP 8.3 pin is moot until CI exists. **No automated CI runs today.** Setting up GitLab CI is new infrastructure and needs a technical-lead decision. A1 records it and does not build it.
- **New security item.** `app/Services/ContractPdfRenderer.php:134` hard-codes a browserless.io token and sends contract pages, including the pay clause, to that external service (commit `3a0e5c49`). Owners: the security reviewer and A6-D. The token must be revoked.
- **AGENTS.md** is stale (`AGENTS.md:6` says Laravel 12 and Livewire 3; `AGENTS.md:26` says PHP 8.4/8.3). This is unchanged, and `CLAUDE.md` is the current source.

## 7. Acceptance check

| AC | Result |
|---|---|
| AC-P0-01: single starting SHA and runtime manifest | Met: §1 records `882a126c` with versions and a fresh test run. Plan claims were checked against git, not taken from the text. |
| AC-P0-02: each prerequisite has a status with evidence | Met: §2 |
| AC-P0-03: synthetic-data test target identified, real records untouched | Met for automated tests (SQLite `:memory:` + fixtures). Browser QA DB and staging are recorded as pending limitations in §3. |

Review sign-off: **approved 2026-10-20** (HR product owner + technical lead, in session). QA engineer role not separately assigned.

## 8. Review addendum (2026-10-20)

- **Baseline re-run on `aceda68e`** (PHP 8.5.11, SQLite `:memory:`): 552 tests, 543 passed, **9 failed**. All 9 are in A1's failure register (#1–6, #11–13); **no new failure**. #7–10 (`ContractPdfRendererTest`) now pass after `926b12f1` (local PDF rendering, remote Chrome opt-in). **New baseline for downstream tasks: `aceda68e`, 552 / 9.**
- **Browser QA database:** resolved in practice by the X1/X2 synthetic SQLite copy outside the repository (`docs/ux/x2/test-environment.md`).
- **`config/samples/sampleData.php`:** HR states that **some of its data is real** and decided to **keep it as is**. Recorded as an accepted privacy risk (real personal data in the repository and its history). It stays unusable as QA data.
  - **Superseded 2026-10-21:** the `employees` block was replaced with fictional people (placeholder national numbers `000000000NN`, phones `09000000NN`), and `EmployeeContractSeeder` was updated to match. The real records **remain in git history** until it is rewritten.
- **browserless.io token** (`3a0e5c49`): no longer in the source, still in git history. The technical lead confirmed on 2026-10-20 that production **uses the browserless.io cloud service with this same token**, so a **live credential is exposed** to anyone with repository access. **Urgent open item:** issue a new token, set it in the server's `BROWSERSHOT_WS_ENDPOINT`, revoke the old one (no code change). **Policy item pending:** contracts, including the pay clause, are rendered by a third party; options are a self-hosted browserless container on loopback, local Chromium, or recorded risk acceptance.
- **CI and staging:** none; accepted as a recorded limitation. Tests are run locally before each task is marked done.
- **Implementer/reviewer names:** not assigned; HR product owner and technical lead act as reviewers.
