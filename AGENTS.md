# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

HC-HR is an HR management system for "Christian Hope Center" (multi-city Syrian NGO).
Stack: Laravel 12, Livewire 3 (+ Volt + Flux), Tailwind CSS 4, MySQL. Bilingual EN/AR with RTL.

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
- The **web server runs PHP 8.4.21** while the **CLI runs PHP 8.3.21** — they use different `php.ini` files. A change that affects uploads/config must be applied to the web PHP's ini, not just the CLI's. `upload_tmp_dir` is set to `C:/laragon/tmp` in both.
- Tests run on sqlite `:memory:` (see `phpunit.xml`); dev/prod use MySQL.

## Architecture

### Laravel 12 layout
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

### Media / avatars
`Employee implements HasMedia` (Spatie MediaLibrary). Avatars live in the singleFile `avatar` collection with a fallback to `public/images/default-avatar.png`. Read with `getFirstMediaUrl('avatar')`.
- **Never re-encode stored PNGs in place** (e.g. GD `imagesavealpha` on images without an alpha channel) — this turned photo backgrounds black. If thumbnails are ever needed, generate conversions from the source without touching the stored original.

### Livewire conventions
- Components in `app/Livewire/<Domain>/`; reusable form objects in `app/Livewire/Forms/` (Livewire `Form` classes). Extracted domain logic lives in `app/Services/` (e.g. `LeaveBalanceService`).
- Data tables extend `ramonrietdijk/livewire-tables` `LivewireTable` — define `query()` and `columns()`. Eager-load relations in `query()` to avoid N+1 (see `EmployeeTable`).
- `AppServiceProvider` registers query Builder macros (`whereLikeAny`, `search`) and the `@glyph` Blade directive (Arabic glyph shaping).

### Brand / theming
Brand primary red is `#c2151c`, defined as `--color-primary` plus a full ramp in `resources/css/app.css` (Tailwind 4 `@theme`). Fonts: SomarSans (with Lato fallback), preloaded in `resources/views/partials/head.blade.php`. Required form fields are marked with a red star via the `required` prop on `<x-forms.input-label>`.

### Routes
`routes/web.php` is the locale-wrapped entry point and `require`s the domain route files: `basic-project.php`, `projects-management.php`, `employees-management.php`, and `auth.php`.
