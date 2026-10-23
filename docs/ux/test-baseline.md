# A1 — Test baseline (proposed)

Status: **approved 2026-10-20** (HR product owner + technical lead). See §9 for the re-run.
Date: 2026-10-16. Task: A1 in `docs/ux/ux-prompts-v3.md` (R-24).

## 1. Starting state

| Item | Value |
|---|---|
| Branch / SHA | `main` @ `e9c89b8dc97ce4d7402adb2f236a47c87bd13b24` (2026-10-16), clean before A1 edits |
| Plan's reviewed SHA | `0d7cd8d7…` is an ancestor. P0 reconciled it to `882a126c`, and `e9c89b8d` adds only the v3 plan doc ([start-state.md](start-state.md) §6a) |
| PHP | 8.5.11 (ZTS, x64) — `C:/laragon/bin/php/php-8.5/php` |
| Laravel / Livewire / PHPUnit | 13.33.0 / 4.4.6 / 12.5.35 |
| SQLite (PDO) | 3.53.4, `:memory:` |
| OS | Windows 11, Laragon, Git Bash |

**P0 prerequisite:** [start-state.md](start-state.md) holds the runtime manifest, the component inventory and the QA target. It is a draft and has not been signed off yet. A1 reproduced its 544/531/13 result on `e9c89b8d`.

**Limitations carried from P0 (not fixed by A1):**
- **No CI runs.** The remote is GitLab, the repo has no `.gitlab-ci.yml`, and `.github/workflows/security.yml` is inert (and pins PHP 8.3). Needs a technical-lead decision.
- **Seed data is not synthetic.** `config/samples/sampleData.php` must not seed a browser-QA database (privacy owner decision).
- **No staging or disposable browser-QA database yet.**

## 2. What changed (harness only)

| File | Change | Why |
|---|---|---|
| `phpunit.xml` | `APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en` added; `APP_ENV`, `APP_LOCALE*`, `DB_CONNECTION`, `DB_DATABASE` pinned with both `<server>` and `<env force="true">` | A shell-exported value used to win over `phpunit.xml`. `<env force>` alone is **not enough**: PHPUnit then sets only `getenv()`/`$_ENV`, while Laravel's env repository reads `$_SERVER` first. `<server>` always overwrites `$_SERVER`. |
| `tests/TestCase.php` | DB guard now fires unconditionally (env ≠ `testing` **or** not SQLite `:memory:` → abort) | Previously the guard only ran when `APP_ENV` was already `testing`, so an inherited `APP_ENV=local` skipped it entirely. |

`.env` was not touched. No assertion was changed. Arabic-specific tests (`CenterLocalizationTest`, `*EnumTest`, `ContractScheduleAndPayColumnsTest`, `DashboardStatisticsTest`, `ContractRenewalTest::…renders_in_arabic`) already call `app()->setLocale('ar')` explicitly, so they stay meaningful.

## 3. Reproducible command

```bash
php artisan test --log-junit <outside-repo>/junit.xml
```

Use PHP 8.5 (above). JUnit/text output is kept outside the repository (session scratchpad), per the task's verification note; not committed.

## 4. Evidence

| Run | Environment | Tests | Assertions | Pass | Fail | Error | Skip |
|---|---|---|---|---|---|---|---|
| R1 neutral (after A1) | no inherited vars | 544 | 2582 | 531 | 8 | 5 | 0 |
| R2 inherited Arabic, **before** A1 (old XML) | `APP_LOCALE=ar APP_FALLBACK_LOCALE=ar` | 544 | 2514 | 487 | 52 | 5 | 0 |
| R3 inherited Arabic + hostile env (after A1) | `APP_LOCALE=ar APP_FALLBACK_LOCALE=ar APP_ENV=local DB_CONNECTION=mysql DB_DATABASE=<operational>` | 544 | 2582 | 531 | 8 | 5 | 0 |

R2 shows the defect A1 fixes: 44 extra failures, almost all `302` locale redirects (`/login` → `/ar/login`), plus Arabic strings in assertions written for English.

### 4.1 R3 result

Identical to R1: same totals and the **same 13 failing test IDs** (JUnit set diff R1 vs R3 is empty both ways). Ran on SQLite `:memory:` despite the hostile `APP_ENV`/`DB_*`. The inherited locale no longer moves the baseline, and the Arabic-specific tests (which set `ar` explicitly) all pass.

### 4.2 Guard probes (AC-A1-01)

| Probe | Result |
|---|---|
| Old XML + `APP_ENV=local DB_CONNECTION=mysql DB_DATABASE=<operational>`, new guard | Exit 2, `Tests are blocked…` in `createApplication()`, before `RefreshDatabase` migrates. MySQL untouched. |
| XML forcing `mysql` / `<operational>` (temp copy), new guard | Exit 2, `Tests are blocked…`, no migration output. |
| Current XML + same hostile env, `<env force>` only (intermediate state) | Guard aborted — this is how the `$_SERVER` gap was found. |
| Current XML (with `<server>`) + hostile env + `APP_LOCALE=ar`, filtered (`ExampleTest\|CenterLocalizationTest\|PayModeEnumTest`) | 5 passed on SQLite — env pinned, Arabic tests still assert Arabic. |

## 5. Failure register (R1, neutral)

13 failures = the plan's main audit (8 failures + 5 errors: security 5, contracts/PDF 7, duplicate notification 1). **None is waived**; none is an environment/harness issue, so A1 repairs none of them.

| # | Test | Symptom | Class | Evidence | Destination |
|---|---|---|---|---|---|
| 1 | `Security\AuthorizationRegressionTest::test_the_profile_drawer_refuses_an_employee_from_another_city` | 200, expected 403 | **Application defect — critical (cross-city data exposure)** | `tests/Feature/Security/AuthorizationRegressionTest.php:118` | A2 (via A2-D) |
| 2 | `SecurityAuthorizationTest::test_digital_id_uses_an_unguessable_token_and_hides_inactive_employees` | inactive employee's card still 200, expected 404 | **Application defect — privacy** | `tests/Feature/SecurityAuthorizationTest.php:64-74` | A2-D → A2 (public card policy) |
| 3 | `Security\PrivateAvatarAccessTest::test_a_disabled_digital_id_stops_resolving` | 404 before disabling, expected 200 | App defect or outdated expectation — undecided; conflicts with #2 (route resolves there) | `…/PrivateAvatarAccessTest.php:129` | A2-D |
| 4 | `Security\PrivateAvatarAccessTest::test_rotating_the_token_invalidates_the_printed_card` | new token 404, expected 200 | Same as #3 | `…/PrivateAvatarAccessTest.php:142-151` | A2-D |
| 5 | `LivewireMutationCoverageTest::test_every_public_livewire_action_is_classified` | 6 unclassified public actions: `contract.contracts-table::{confirmToggleAutoRenew, toggleAutoRenewConfirmed, updatedPaginators}`, `position.org-chart::toggleVacancies`, `position.position-{create,edit}::updatedFormCityId` | **Application defect — security (unauthorized server actions)** | `config/access-control.php` | A2 |
| 6 | `ContractPayClauseTest::test_for_pdf_true_does_not_throw_and_still_carries_the_amount` | `Class "App\Support\ArabicText" not found` | **Application defect** — class deleted in `3a0e5c49`, still imported by `components/contract/pay-clause.blade.php`, `schedule-clause.blade.php`, `livewire/contract/details/annual/pages/page{1,2,3}.blade.php` | PDF pay clause throws | A6-D → A6 |
| 7–10 | `ContractPdfRendererTest` (4 tests) | `FileUrlNotAllowed: '<h1>x</h1>' is not a valid URL` | **Application defect** — `ContractPdfRenderer::browsershot()` now calls `InspectableBrowsershot::url($html)` (was `html()`) and connects to a remote WS endpoint. See §6. | `app/Services/ContractPdfRenderer.php:130-140` | A6-D → A6 + security |
| 11 | `ContractCreatePerformanceTest::test_the_composer_renders_all_three_sections_and_locks_the_single_city` | `id="composer_center"` not rendered | App defect or outdated expectation — needs A6-D to decide if the center field was removed intentionally | `tests/Feature/ContractCreatePerformanceTest.php:102` | A6-D |
| 12 | `ContractRenewalTest::test_the_renew_page_ignores_a_malformed_query_string` | `assertDontSee('<script>', false)` fails on the full page | **Outdated/flawed expectation** — every full page contains Livewire/Vite `<script>` tags, so the assertion cannot distinguish an XSS echo. Intent (query value must not be echoed raw) is valid; the assertion should target the injected value, not the tag. Not changed here (not a harness issue). | `tests/Feature/ContractRenewalTest.php:718` | A6-D |
| 13 | `ContractAutoRenewalWorkflowTest::test_warning_command_does_not_repeat_same_contract_on_same_day` | 2 notifications, expected 1 | **Application defect** — duplicate expiry warning | `tests/Feature/ContractAutoRenewalWorkflowTest.php:139-156` | B6 |

## 6. Security finding raised during A1 (out of A1 scope — escalate)

`app/Services/ContractPdfRenderer.php:134` (commit `3a0e5c49`, 2026-09-18, on `main`) hard-codes a **browserless.io token** and sends contract pages — which carry the pay clause — to that third-party service. This conflicts with SHARED RULES (salary privacy) and the documented Browsershot/local-Chromium design in `CLAUDE.md`. Required owner action: rotate/revoke the token, remove it from source, decide whether external rendering is permitted at all. Destination: A6-D + security reviewer. A1 did not modify it.

## 7. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| AC-A1-01 tests abort on operational DB | **Met** | §4.2 |
| AC-A1-02 inherited Arabic does not change neutral baseline; Arabic checks meaningful | **Met** | §4, §4.1 (R1 = R3; R2 shows the pre-A1 drift) |
| AC-A1-03 every remaining failure has owner + task; critical defects not waived | **Met** | §5 (#1, #5, §6 flagged critical) |

## 8. Handoff

Consumers: X1, X2, A2-D, A3-D, A6-D, B5-D. Baseline to compare against: R1 (544 / 531 pass / 13 failing as listed). Any new failure outside §5 is a regression.

## 9. Review re-run (2026-10-20, on `aceda68e`)

552 tests, 543 passed, 9 failed. Failing set = §5 #1, #2, #3, #4, #5, #6, #11, #12, #13; **no failure outside §5**. #7–10 now pass (`926b12f1`, local PDF rendering).

Note for A2: #2, #3 and #4 assert an unguessable random card token. The A2-D decision of 2026-10-20 (access-policy.md §9 Q6) chose a readable card link instead, so these tests encode an **outdated expectation** and must be rewritten against the decided rule (only the latest plastic index resolves; cardboard stops when plastic is issued; inactive employee shows "card is not valid"), not satisfied by changing the code back.

§6 browserless token: removed from source by `926b12f1`, but **still live in production** (same token, browserless.io cloud; confirmed 2026-10-20). Rotation is urgent; third-party rendering of the pay clause awaits a decision (see start-state.md §8).
