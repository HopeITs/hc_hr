# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Designer / design-only AI tasks

For designer work or any task described as design-only, read and follow
`DESIGN_ONLY.md` before editing. It permits CSS and safe presentation edits in
Blade/Volt templates while protecting all application logic and behavior.

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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== volt/core rules ===

# Livewire Volt

- Single-file Livewire components: PHP logic and Blade templates in one file.
- Always check existing Volt components to determine functional vs class-based style.
- IMPORTANT: Always use `search-docs` tool for version-specific Volt documentation and updated code examples.
- IMPORTANT: Activate `volt-development` every time you're working with a Volt or single-file component-related task.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== maatwebsite/excel/core rules ===

# Laravel Excel

- Use `maatwebsite/excel` for spreadsheet exports, imports, queued spreadsheet work, CSV handling, and PhpSpreadsheet integration in Laravel applications.
- Prefer explicit export/import classes with package concerns over ad-hoc spreadsheet generation in controllers, jobs, or commands.
- Activate the `laravel-excel` skill when working with `Excel::download()`, `Excel::store()`, `Excel::queue()`, `Excel::raw()`, `Excel::import()`, `Excel::toArray()`, `Excel::toCollection()`, export/import concerns, queued imports/exports, validation, CSV settings, styling, events, formulas, charts, drawings, multiple sheets, mapped cells, macros, config, cache, transactions, temporary files, or `Excel::fake()`.
- For broad docs, all-feature tasks, or missing-feature audits, use the skill's `references/package.md` feature matrix before answering.
- For large datasets, prefer `FromQuery` with queued exports or `WithChunkReading` and `WithBatchInserts` for imports.
- Test spreadsheet behavior with `Excel::fake()` when asserting dispatch/download/store/import intent, and inspect generated files only when cell contents, formatting, sheets, or writer behavior must be proven.

=== spatie/laravel-medialibrary/core rules ===

## Media Library

- `spatie/laravel-medialibrary` associates files with Eloquent models, with support for collections, conversions, and responsive images.
- Always activate the `medialibrary-development` skill when working with media uploads, conversions, collections, responsive images, or any code that uses the `HasMedia` interface or `InteractsWithMedia` trait.

</laravel-boost-guidelines>
