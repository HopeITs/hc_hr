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
`ContractPdfRenderer` (in `app/Services/`) renders `resources/views/pdf/contract.blade.php` through Browsershot; the controller only authorizes and sets headers. Host-specific settings: `BROWSERSHOT_CHROME_PATH`, `BROWSERSHOT_NODE_BINARY`, `BROWSERSHOT_TIMEOUT`, and the optional `BROWSERSHOT_WS_ENDPOINT`.
- **Production renders through browserless.io** (`BROWSERSHOT_WS_ENDPOINT`, decided 2026-10-20): the shared host has no usable Chromium, so contracts — pay clause included — are rendered by that service. The token lives only in the server's `.env`; with the endpoint set, `chrome_path` is ignored.
- **On a Linux server point `BROWSERSHOT_CHROME_PATH` at the distribution's own Chromium** (`/usr/bin/chromium`). A Chrome unpacked by hand into a home directory brings none of the system integration it expects and dies during startup with nothing on stderr but D-Bus and PCI complaints.
- **Nothing large may travel on node's stdout.** Browsershot parses that stdout as JSON and treats a parse failure as *success* — `$output['result'] ?? ''`, base64-decoded, is an empty string returned with exit code 0. `savePdf()` (not `pdf()`) plus `disableCaptureURLS()` keeps stdout small; with `pdf()` and URL capture it was ~635 KB per contract and whatever truncated it on the server surfaced as a 0-byte "PDF".
- `php artisan contracts:export-doctor [contract]` runs the same pipeline on a host and reports Chrome, node, puppeteer resolution, directory permissions and the raw stdout/stderr/exit code, printing Chrome's own output in full when a launch fails. Run it on the server before changing anything.

### Livewire conventions
- Components in `app/Livewire/<Domain>/`; reusable form objects in `app/Livewire/Forms/` (Livewire `Form` classes). Extracted domain logic lives in `app/Services/` (e.g. `LeaveBalanceService`).
- Data tables extend `ramonrietdijk/livewire-tables` `LivewireTable` — define `query()` and `columns()`. Eager-load relations in `query()` to avoid N+1 (see `EmployeeTable`).
- `AppServiceProvider` registers query Builder macros (`whereLikeAny`, `search`) and the `@glyph` Blade directive (Arabic glyph shaping).
- **Dashboard tiles** (`DashboardLayout::WIDGETS` + `::COMPONENTS`, DESIGN.md "Dashboard tiles"): every widget is a Livewire component mounted **lazily** in its tile and a hidden tile is not rendered at all. A lazily mounted card arrives in a Livewire update, so an inline `<script>` in its view never runs and `@push` never reaches the layout: use `@script` (it runs before the card's markup replaces the placeholder: wait a frame) or `@assets`. Livewire's tests render lazy children as placeholders; `Livewire::withoutLazyLoading()->test(...)` renders them in place (it is reset by the next `Livewire::test`, not by a plain `$this->get`).

### Brand / theming
The visual identity is the Hope Center one as implemented in **media-request** (`C:\laragon\www\media-request\public\css\app.css` is the reference; DESIGN.md has the full spec). All of it is Tailwind 4 `@theme` variables in `resources/css/app.css`, so every value is also a utility.
- **Palette:** one flat red `#BE1F24` (`--color-primary`; hover = the same red at 90%) and the identity neutrals: ink `#414042`, ink-2 `#6D6E71`, ink-3 `#939598`, line `#BCBEC0`, line-soft / surface `#E6E7E8`, soft `#F3F3F4`, card `#FFFFFF`. New work uses the semantic utilities (`bg-card`, `bg-soft`, `bg-surface`, `bg-base`, `text-ink`, `text-ink-2`, `text-ink-3`, `border-line`, `border-line-soft`, `bg-ink`/`text-on-ink`, `bg-hover`). The legacy names (`secondary-*`, `neutral-50…950`, `accent`) still work and land exactly on those neutrals.
- **No other hue, anywhere** — not for statuses, charts, alerts or success. `resources/css/palette-lock.css` maps every Tailwind/Flowbite/Flux family onto the brand (red/rose 50–200 → neutral, 300–600 → primary, 700+ → primary-hover; every other hue → the neutral step of the same number), so a stray `bg-green-600` can only paint a neutral. Charts read only the brand variables (`resources/js/charts/brand-colors.js`).
- **Red has three jobs only:** the primary action, the current stage / current page, and what waits on the viewer or is late. Never tint it into a pale/pink fill.
- **Status = icon + words + fill shape, never colour** (in progress = solid ink, done = surface, needs you = ink outline, stopped = dashed outline, waiting = card + line outline, late = solid red tag, urgent = red outline tag).
- **Shared components (never hand-roll these):** `<x-status :status="…">` (or `tone`/`icon`/`label`) for every status, `<x-tag variant="act|late|rush|soft">`, `<x-button variant="primary|outline|quiet|link" size="sm|md|lg">`, `<x-page-header>`, `<x-card>`, `<x-center-empty-state>`, `<x-ic name="…">` icons. Status enums implement `App\Support\Status\HasStatusTone` (`tone()`, `icon()`, `label()`): `LeaveRequestStatus`, `EmployeeStatus::for($employee)`, `ContractStatus::for($contract)`. The classes behind them are in `resources/css/components.css`; `/{locale}/_ui` (local/testing only) shows every component in every state. `IdentityComponentsTest` fails if a view uses an `emerald-/amber-/green-/yellow-/blue-` class.
- **Dark mode** follows the system unless `<html data-theme="light|dark">` overrides it; the inline script in `partials/head.blade.php` applies the stored choice (`localStorage` `hopehr-theme`) before first paint and after `wire:navigate`, and `<x-theme-toggle>` in the header switches it. Dark redefines the same variables: the neutrals (including the legacy `neutral-*` ramp) invert and the red stays. So **never write `dark:bg-neutral-800`-style pairs** — use a semantic utility and it flips by itself; the `dark:` variant is for the rare thing a token cannot express.
- **Type:** Somar Sans 400–800. Body 16px/1.55 (Arabic 17px/1.7 via `html[lang="ar"]`), tables 15px, table headers 13px, hints `text-small` 14px, counters `text-caption` 12px, h1 `clamp(26px,4vw,34px)`/800 balanced. `num` (or `tabular-nums`) for figures.
- **Focus** is a 2px ink outline with 2px offset, never red; inputs get an ink border + 3px ring. Both are unlayered rules in `app.css`, so they win over old `focus:*-primary` utilities.
- Fonts: SomarSans (with Lato fallback), Regular and Medium preloaded in `resources/views/partials/head.blade.php`.
- Required form fields are marked with a red star (plus a screen-reader-only "required" word) via the `required` prop on `<x-forms.input-label>`.
- **Web fonts live in `resources/fonts/` as WOFF2** and are referenced from `app.css` by relative path, so Vite fingerprints them (cache-busting) and the preload uses `Vite::asset()` to get the same URL. Never point `@font-face` at `/fonts/...` again — that is what produced the "didn't resolve at build time" warnings.
- `public/fonts/` holds only the `.ttf` files the contract PDF inlines through `public_path()` (`_styles.blade.php`). Those two sets are separate on purpose.

### Routes
`routes/web.php` is the locale-wrapped entry point and `require`s the domain route files: `basic-project.php`, `projects-management.php`, `employees-management.php`, and `auth.php`.
