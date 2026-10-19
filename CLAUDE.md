# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

HC-HR is an HR management system for "Christian Hope Center" (multi-city Syrian NGO).
Stack: PHP 8.5, Laravel 13, Livewire 4 (+ Volt + Flux), Tailwind CSS 4, Vite 8, MySQL. Bilingual EN/AR with RTL.

## Role

Act as an expert senior TALL stack (Tailwind, Alpine.js, Laravel, Livewire) developer on this codebase: idiomatic Livewire/Volt/Flux patterns, clean Laravel architecture, and Tailwind-first styling consistent with the conventions documented below.

## Commands

```bash
composer dev          # run server + queue:listen + vite concurrently (primary dev loop)
npm run dev           # vite only
npm run build         # production asset build
composer test         # config:clear then php artisan test
php artisan test --filter=SomeTest   # single test
./vendor/bin/pint     # format / lint (Laravel Pint)
php artisan storage:link             # REQUIRED — public/storage must be a symlink, not a real dir
```

Custom artisan commands (in `app/Console/Commands`):
- `php artisan leaves:backfill` — generate deserved leave balances for Excel-imported contracts
- `php artisan employees:import-images <path> [--force]` — import avatars into the Spatie `avatar` media collection
- contract / employee deactivation commands

### Environment gotchas (Laragon on Windows)
- The app requires **PHP 8.5** (`C:/laragon/bin/php/php-8.5`), for both the web server (Laragon → PHP menu) and the CLI. Older PHP installs are still in `C:/laragon/bin/php`, and each version has its own `php.ini`, so a change that affects uploads/config must go into 8.5's. `upload_tmp_dir` is `C:/laragon/tmp`.
- Livewire 4 tests: a `ModelNotFoundException` inside a component call becomes a 404 response instead of being thrown. Assert it with `->assertNotFound()`, not `expectException`.
- Vite 8 bundles with Rolldown: `manualChunks` must be a function, not an object.
- Tests run on sqlite `:memory:` (see `phpunit.xml`); dev/prod use MySQL.

## Architecture

### Laravel 13 layout
No `app/Http/Kernel.php`. Middleware aliases, routing, and exceptions are configured in `bootstrap/app.php`. Service wiring (observer registration, Blade directives, query macros) lives in `app/Providers/AppServiceProvider.php`.

### Localization & translatable columns
- URLs are locale-prefixed (`/en/...`, `/ar/...`) via `mcamara/laravel-localization`. All web routes are wrapped in the locale group in `routes/web.php`; `AppServiceProvider` re-registers the Livewire update route under the locale prefix.
- **Translatable model fields use the `{attribute}_{locale}` column convention** (e.g. `first_name_en` / `first_name_ar`, `title_en` / `title_ar`). The `HasTranslation` trait + accessors expose a single virtual attribute that resolves to the current locale. In queries/columns, build the physical column name with the `transColumn('first_name')` helper.
- Helpers are autoloaded from `app/Support/helpers.php`: `transColumn`, `ar_digits`/`en_digits`, `formatDuration`, `esc`.

### Roles, cities & scoping (security-critical)
- Spatie Permission. Roles are city-scoped: `HR-SYR`, `HR-ALP`, `HR-HMS`, etc. The `City` table's `slug` holds the scope code (SYR/ALP/HMS/DMS/LAT/TAR); `code` holds the numeric id (000/001/...).
- `App\Http\Middleware\RoleMiddleware` (alias `role`) expands `role:HR` to **all** `HR-%` roles. Use `role:HR|super-admin|...` in route definitions.
- `User::myRole()` returns the active role (the `roles` pivot row with `is_active = true`, falling back to first). `User::activeRoleIs()` and the `@activerole` / `@notactiverole` Blade directives normalize any `HR-*` to `HR`.
- **`Position::scopeInCity($cityId)` is the single source of truth for city scoping** (`city_id` OR `center.city_id`). Any contract create/edit dropdown filter AND its matching validation must both go through this scope — divergence here was the cause of the "invalid position in Syria" bug.

### Contracts & position capacity
`ContractObserver` (registered in `AppServiceProvider`) keeps each `Position.exist_emp` counter in sync using `lockForUpdate`, and enforces capacity via `Position::canAssign()`. It **skips the capacity check when `runningInConsole()`** so bulk Excel/CLI imports don't fail.

### Pay figures (security-critical)
`contracts.salary` and `contract_pay_tiers.amount` are **encrypted at rest under their own key**, not under `APP_KEY` — a database copy usually travels with a developer `.env`, and `APP_KEY` with it. The key is `SALARY_ENCRYPTION_KEY` (`php artisan salary:key:generate`), set on production only; `config/salary.php` falls back to `APP_KEY` so local databases and the suite keep working.
- `App\Services\SalaryCipher` returns **null instead of throwing** for a value it cannot decrypt — a copy without the key must stay usable. `App\Casts\EncryptedSalary` then **refuses to overwrite a figure it could not read**, so working on such a copy cannot destroy the originals. Its `compare()` decrypts before diffing; without it every save would look like a pay change (fresh IV each time).
- Decryption failure is silent by design, so a misconfigured host looks exactly like a working one. `php artisan salary:key:check` is what tells them apart — run it after any deploy or key change.
- The migration widens the columns to `text` **except on SQLite**, where `change()` rebuilds the table and dropping `contracts` fires `contract_pay_tiers`' `ON DELETE CASCADE`, wiping every flexible contract's pay schedule. SQLite type affinity keeps the envelope verbatim anyway.
- **`User::canViewSalary()` is the single source of truth for who may see a figure**, and `super-admin` is refused by name in `config('salary.blind_roles')` — it holds every permission automatically, so a `salaries.view` permission would be granted to the one role that must not have it. Callers add their own city scope, the same way they already do for every other HR-only view.
- Roles that may not see figures get them **withheld on load and preserved on save** (`ContractForm::preserveHiddenPay`, `BadgeForm::save`) — never disabled inputs, because the values are never sent to that browser at all. The PDF export is refused outright: the document *is* the pay clause.

### Media / avatars
`Employee implements HasMedia` (Spatie MediaLibrary). Avatars live in the singleFile `avatar` collection with a fallback to `public/images/default-avatar.png`. Read with `getFirstMediaUrl('avatar')`.
- **Never re-encode stored PNGs in place** (e.g. GD `imagesavealpha` on images without an alpha channel) — this turned photo backgrounds black. If thumbnails are ever needed, generate conversions from the source without touching the stored original.

### Contract PDF export
`ContractPdfRenderer` (in `app/Services/`) renders `resources/views/pdf/contract.blade.php` through Browsershot; the controller only authorizes and sets headers. Only three settings are host-specific — `BROWSERSHOT_CHROME_PATH`, `BROWSERSHOT_NODE_BINARY`, `BROWSERSHOT_TIMEOUT`.
- **On a Linux server point `BROWSERSHOT_CHROME_PATH` at the distribution's own Chromium** (`/usr/bin/chromium`). A Chrome unpacked by hand into a home directory brings none of the system integration it expects and dies during startup with nothing on stderr but D-Bus and PCI complaints.
- **Nothing large may travel on node's stdout.** Browsershot parses that stdout as JSON and treats a parse failure as *success* — `$output['result'] ?? ''`, base64-decoded, is an empty string returned with exit code 0. `savePdf()` (not `pdf()`) plus `disableCaptureURLS()` keeps stdout small; with `pdf()` and URL capture it was ~635 KB per contract and whatever truncated it on the server surfaced as a 0-byte "PDF".
- `php artisan contracts:export-doctor [contract]` runs the same pipeline on a host and reports Chrome, node, puppeteer resolution, directory permissions and the raw stdout/stderr/exit code, printing Chrome's own output in full when a launch fails. Run it on the server before changing anything.

### Livewire conventions
- Components in `app/Livewire/<Domain>/`; reusable form objects in `app/Livewire/Forms/` (Livewire `Form` classes). Extracted domain logic lives in `app/Services/` (e.g. `LeaveBalanceService`).
- Data tables extend `ramonrietdijk/livewire-tables` `LivewireTable` — define `query()` and `columns()`. Eager-load relations in `query()` to avoid N+1 (see `EmployeeTable`).
- `AppServiceProvider` registers query Builder macros (`whereLikeAny`, `search`) and the `@glyph` Blade directive (Arabic glyph shaping).

### Brand / theming
Brand palette is `--color-primary` `#be1f24` plus four greys (`--color-secondary-dark/medium/light/soft`) — the only colours allowed; every other token (neutral ramp, surfaces, hover) is derived from them in `resources/css/app.css` (Tailwind 4 `@theme`).
- **No other hue is allowed anywhere — no green, yellow, blue, orange, purple, not even for statuses or charts.** `resources/css/palette-lock.css` redefines every Tailwind/Flowbite colour family in terms of the brand tokens (red/rose → primary, everything else → the neutral step of the same number), so a stray `bg-green-600` can only paint grey. Don't rely on it: write brand classes (`bg-primary`, `text-secondary-dark`, `bg-neutral-100`…).
- Statuses are told apart by brand tone, not hue: solid red for the active/positive state, a red outline (`ring-1 ring-inset ring-primary`) for its sibling, then the greys from dark to soft for final/negative states (see `RecruitmentInterviewStatus::classes()` / `dotClasses()`).
- JS that reads colours from CSS must read only the five brand variables; the derived tokens are `color-mix()` expressions chart libraries can't parse.

Fonts: SomarSans (with Lato fallback), preloaded in `resources/views/partials/head.blade.php`. Required form fields are marked with a red star via the `required` prop on `<x-forms.input-label>`.
- **Web fonts live in `resources/fonts/` as WOFF2** and are referenced from `app.css` by relative path, so Vite fingerprints them (cache-busting) and the preload uses `Vite::asset()` to get the same URL. Never point `@font-face` at `/fonts/...` again — that is what produced the "didn't resolve at build time" warnings.
- `public/fonts/` holds only the `.ttf` files the contract PDF inlines through `public_path()` (`_styles.blade.php`). Those two sets are separate on purpose.

### Routes
`routes/web.php` is the locale-wrapped entry point and `require`s the domain route files: `basic-project.php`, `projects-management.php`, `employees-management.php`, and `auth.php`.
