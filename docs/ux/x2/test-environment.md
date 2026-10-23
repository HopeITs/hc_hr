# X2 · Session test environment (proposed)

Part of [usability-baseline.md](../usability-baseline.md). Task X2 (R-15).
Status: **built and checked on a disposable SQLite copy on 2026-10-19; awaiting review.** No session has been run on it.

This file describes the synthetic data the four task scripts ([task-scripts.md](task-scripts.md)) need, and how to build it. Everything here runs on a **separate SQLite file outside the repository**. It never touches the operational MySQL database, `.env`, real employees, or X1's files.

## 1. What was added

| File | What it does | Touches |
|---|---|---|
| `tests/Usability/Baseline/x2-home.php` | Points the X1 harness at X2's own folder (`X2_HOME`, default `<system temp>/hc-hr-x2`) and port 8391. **Refuses to run** if `X2_HOME` is inside the repository. | Environment variables only |
| `tests/Usability/Baseline/session-states.php` | Adds the session states (§2) on top of the X1 synthetic database in `X2_HOME`, and writes `X2_HOME/x2-session-manifest.json`. Refuses to run unless the X1 fixture was built there first. | `X2_HOME/perf.sqlite` only |
| `tests/Usability/Baseline/router.php` | Serves the session copy of the app: sets X2's home, then hands over to X1's router (SQLite, mail to `array`, production mode). | Nothing; serves pages |

**X1's files are reused unchanged** (`tests/Performance/Baseline/build-fixtures.php`, `env.php`, `router.php`). Through `env.php`, every entry point forces `DB_CONNECTION=sqlite` with the database in `X2_HOME`, and calls `perf_assert_isolated()` after boot. If the config is cached, or the booted database is anything else, it aborts before writing.

Nothing is registered with Laravel. `DatabaseSeeder`, migrations and app code are unchanged. These scripts are not PHPUnit tests and are not picked up by `composer test`.

## 2. Session states (per city: ALP and HMS)

The X1 build supplies the organisation reference data and about 278 synthetic employees with `*@example.test` accounts (names drawn from a fixed list, fixed seed). `session-states.php` adds, **in each of two cities**:

| Need (from the request) | Account in the manifest | State created | Script |
|---|---|---|---|
| HR account | `hr.<city>@example.test`, role `HR-ALP` / `HR-HMS` | — | B, C (HR variant), D |
| Manager with a team member's pending leave | `manager.<city>@example.test`, role `manager` | The manager's position is the **direct manager** of 3 reports. Each report has exactly one **Pending**, annual, day request (other pending requests of those reports are removed). The first is the **target**; the other two are distractors. The X1 build adds a few more pending requests in scope. | C |
| Employee account | `staff.<city>@example.test`, role `employee` | Annual balance reset to at least 10 working days, used = 0. The manifest gives a target date: a working day ≥ 8 days ahead, not a center weekend, not in December. | A |
| Lookup target | — | An employee whose English surname is shared by ≥ 3 people in the city (has an account, so a work email). | B |
| Contract-ready employee | — | New synthetic employee `x2.hire.<city>`, active, **no contract**; a position in that city with **at least two free seats** (raised if needed). Start/end dates and a 5 × 8 schedule are suggested in the manifest. **Pay is chosen by the moderator and never recorded.** | D |
| Draft reservation | — | New synthetic employee `x2.draft.<city>` with a **Draft-type** contract on the same position. A Draft does not claim a seat until it is completed, so D and D′ can both succeed in one session. | D′ |

The password for every account is the X1 fixture password, written in `X2_HOME/fixture-manifest.json` and `x2-session-manifest.json` (both outside the repository). The moderator signs participants in; participants never see or type it.

### 2.1 Check run on 2026-10-19 (data check, not a usability result)

Built into a scratch `X2_HOME` and checked with the app's own scopes (`LeaveRequest::manageableBy`, `User::myRole`):

| Check | ALP | HMS |
|---|---|---|
| Manager's active role | `manager` | `manager` |
| Target request visible to the manager as pending | yes | yes |
| Pending requests in the manager's scope (target + distractors) | 4 (own city only) | 5 (own city only) |
| Manager's bell | 3 (its own requests) | 3 |
| Staff bell | 0 | 0 |
| HR / staff active roles | `HR-ALP` / `employee` | `HR-HMS` / `employee` |
| Task B: people sharing the target's surname in the city | 5 | 9 |
| Task D employee's contracts | 0 | 0 |
| Task D position seats (used/max) | 0/2 | 0/2 |
| Task D′ Draft reservations | 1 | 1 |

Fixes made during this check: (1) a direct report whose contract started recently had no annual balance, and the script stopped. It now creates a synthetic annual balance for the session personas. (2) The vacant position had one seat, so D followed by D′ would have hit "no seats left". It now guarantees two free seats. `pint --test` passes on the folder.

Further fixes after the browser walkthrough ([dry-run-2026-10-19.md](dry-run-2026-10-19.md) §3, items 4–6), and the table above re-run: (3) the two city managers had landed on one shared head-office position, so each saw both cities' pending requests (7 in scope each); each city's manager now holds a different position. (4) The Task B target was on the first page of the employee list; it is now taken from beyond the first two pages. (5) X1's random notifications are removed from the staff personas, and each manager gets only the notices for its own three requests.

Walked in a browser (EN, ALP personas): Tasks A–D, see [dry-run-2026-10-19.md](dry-run-2026-10-19.md). Not checked yet (pilot items, [recruitment-and-sessions.md §5](recruitment-and-sessions.md)): Arabic, the HMS personas, D′, and the reset (step 5).

## 3. How to run it

Requirements: PHP 8.5 with `pdo_sqlite` (see the project's test command), `vendor/` installed, `npm run build` done, and **no** `bootstrap/cache/config.php` (run `php artisan config:clear` if it exists; the harness refuses otherwise).

Choose a folder **outside** the repository, for example `C:/laragon/tmp/hc-hr-x2`.

1. Build the X1 synthetic database into X2's folder (about 10 s; it deletes and recreates only `X2_HOME/perf.sqlite`):

```bash
X2_HOME=C:/laragon/tmp/hc-hr-x2 C:/laragon/bin/php/php-8.5.11/php.exe -d extension=pdo_sqlite -d extension=sqlite3 -d memory_limit=2G -r "require 'tests/Usability/Baseline/x2-home.php'; require 'tests/Performance/Baseline/build-fixtures.php';"
```

2. Add the session states and write the manifest:

```bash
X2_HOME=C:/laragon/tmp/hc-hr-x2 C:/laragon/bin/php/php-8.5.11/php.exe -d extension=pdo_sqlite -d extension=sqlite3 tests/Usability/Baseline/session-states.php
```

3. Take a clean snapshot for resets between participants (server stopped):

```bash
cp C:/laragon/tmp/hc-hr-x2/perf.sqlite C:/laragon/tmp/hc-hr-x2/perf.clean.sqlite
```

4. Serve it on `http://127.0.0.1:8391` (in the Claude desktop app, the `.claude/launch.json` entry "hc-hr X2 usability sessions (synthetic SQLite)" does the same with the default `X2_HOME`, `<system temp>/hc-hr-x2`):

```bash
X2_HOME=C:/laragon/tmp/hc-hr-x2 C:/laragon/bin/php/php-8.5.11/php.exe -d extension=pdo_sqlite -d extension=sqlite3 -S 127.0.0.1:8391 -t public tests/Usability/Baseline/router.php
```

5. **Reset between participants:** stop the server, delete `perf.sqlite-wal` and `perf.sqlite-shm` if present, copy `perf.clean.sqlite` back over `perf.sqlite`, start again. Rebuilding (steps 1–2) gives the same people, but dates are relative to the build day.

6. Fill the scenario cards from `X2_HOME/x2-session-manifest.json`: names (EN/AR), Task A target date, Task C target request and dates, Task D position, center and dates. Rebuild on the session day, because target dates are computed from "today".

The session server listens on `127.0.0.1` only. For a remote screen-share session the moderator shares their own screen and gives control, rather than exposing the port.

## 4. Limits

- **Synthetic, SQLite, production mode, local PHP server.** Page speed differs from the live MySQL host (see X1). Usability timings include that speed; record the target build in each session sheet.
- **Two cities only** (ALP, HMS). Enough for "more than one city"; a third city needs one more entry in the script's city list.
- The data changes the session copy only, including `positions.direct_manager_id` / `max_emp` there. It is not suitable for anything but sessions and must be rebuilt, not reused, for X2-R.
- **Ownership:** the files are new and sit beside, not inside, X1's harness. X1's owner should still confirm the reuse of `build-fixtures.php` (review item in [review-checklist.md](review-checklist.md)).
