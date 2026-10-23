# B1 navigation inventory (AC-B1-01)

Scope: `resources/views`, `app/`, `resources/js`, `public/js`. Commits 8769e98 / dd62fa2 are not rebuilt; this inventory records what is on the branch after them.
Static source inspection only. Browser evidence (EN/AR, back/forward, repeated visits, console) is NOT collected here and AC-B1-02/03 remain open. `vendor/` is not installed in this environment, so Pint and the test suite were not run.

Legend: NAV = `wire:navigate` (client navigation). FULL = intentional full load. FIXED = gap closed in this task. OPEN = gap left, with reason.

Convention: `<x-primary-button>`, `<x-secondary-button>`, `<x-primary-link>`, `<x-table.edit-btn|show-btn|decision-btn>`, `sidebar/nav-item`, `sidebar/single-item` add `wire:navigate` by default when given `href`; opt out with `:wire:navigate="false"`. No call site currently opts out.

## 1. Navigating via shared components (NAV by default)

| file:line | link | behavior | reason |
|---|---|---|---|
| components/primary-button.blade.php:20, secondary-button.blade.php:11, primary-link.blade.php:5 | any `href` | NAV | default merged attribute |
| components/table/edit-btn:3, show-btn, decision-btn | row actions with `href` | NAV | default |
| components/layouts/app/sidebar/nav-item.blade.php:6, single-item.blade.php:7 | all sidebar items (livewire/layout/sidebar.blade.php:46-367) | NAV | merged 8769e98 |
| livewire/layout/sidebar.blade.php:28 | logo to dashboard | NAV | |
| vendor/livewire-table/table/table.blade.php:82 | row click `Livewire.navigate(link)` | NAV | JS navigate |
| components/settings/tab-navigation.blade.php:5; settings/layout.blade.php:4-5 | settings tabs | NAV | |

Call sites of those components (all internal routes, all NAV): employee-module:8,46; employee/index:14; employee/form:18; tabs/contact-info-fields:101; tabs/personal-info-tab:323; contracts-module:21; contract-details:59,63; program/form:77; program/detail:33; centers-module:17; position/index:27; position/form:124; news-module:9; NewsTable:108; EmployeeTable:268; PositionTable:172; ContractsTable:363,365.

## 2. Explicit `wire:navigate`

| file:line | link | behavior | reason |
|---|---|---|---|
| components/layouts/auth/{split:10,30, card:9, simple:12} | logo | NAV | |
| components/layouts/app/notification.blade.php:56; sidebar/profile-card.blade.php:46,97 | notifications / settings.profile | NAV | |
| dashboard-cards/team-members-card:93,130 | profile, org chart | NAV | |
| auth/forgot-password:51, login:65, register:60 | login / password.request | NAV | |
| program/index:13,51,65; program/detail:157; center-details:43,52; center-employees:71 | program / center / employee links | NAV | |
| contract/composer/_action-bar:40 | cancel | NAV | |
| archives/archived-files-module:75 | folder open | NAV | |
| layout/header:120,130; layout/breadcrumbs:11,22 | my-profile, password, crumbs | NAV | |
| dashboard/my-leaves-card:4; upcoming-leaves-card:53; contract-statistics:182 (conditional, `#` card types excluded) | | NAV | |
| employee/employee-profile:81; position/profile-drawer:206; access-management/index:152,166,479,492 | employee links | NAV | |
| program-management/center/center-create:79; news/news-create:16 | cancel via `Livewire.navigate()` | NAV | |

## 3. Livewire PHP redirects

| file:line | link | behavior | reason |
|---|---|---|---|
| Auth/Register:41, ConfirmPassword:35, ResetPassword:85, RoleSwitcher:106, VerifyEmail:20,37 | dashboard/login/home | NAV | `navigate: true` |
| Employee/EmployeeCreate:190,195 | employees.edit | NAV | |
| Auth/Login:88 | redirectIntended after login | FULL | intentional: session regenerates the CSRF token; documented in the file |
| Auth/Logout (Actions/Logout.php:25) | localized home | FULL | intentional: session invalidated, also ends role/locale state |
| Concerns/HandlesNotificationActions:29 | notification target URL | FULL | intentional, documented in the file: read-before-navigate atomicity, and global scripts not idempotent under navigate. Revisit with B1 browser evidence |
| Archives/ArchivedFilesModule:366 | `folder-files.download` | FULL | file download |
| Contract/ContractCreate:146, ContractEdit:104, ContractRenewModal:87,103 | contracts.show / contracts.renew | FIXED | was `redirect()->route()`; now `redirectRoute(..., navigate: true)` |
| Position/PositionCreate:46, PositionEdit:59 | positions.index | FIXED | same |
| ProgramManagement/Program/Create:23, Update:30, Detail:57 | programs.index | FIXED | same |
| ProgramManagement/Center/CenterCreate:59 | centers.index | FIXED | same |
| News/NewsCreate:50 | news.index | FIXED | was `to_route()` |
| Http/Controllers/Auth/VerifyEmailController:17,22; Middleware/EnsureAccountIsActive:60 | HTTP redirects | FULL | plain HTTP controller/middleware, not Livewire actions |

Not links: Notifications `url` values (app/Notifications/*) are only data consumed by the notification UI (see FULL row above).

## 4. HTML built in PHP (table columns)

| file:line | link | behavior | reason |
|---|---|---|---|
| Employee/EmployeeTable:261 | view profile | FIXED | had no `wire:navigate` |
| ProgramManagement/Center/CentersTable:220 (positions), :231 (view) | | FIXED | same |
| News/NewsTable:44, Employee/EmployeeTable:110, PositionTable:108, ContractsTable:160 | row `link()` URLs | NAV | consumed by `Livewire.navigate` in table.blade.php |

## 5. Intentional full loads (not gaps)

| file:line | link | reason |
|---|---|---|
| layout/header:39,42 | EN / AR locale switch | locale change must reload; documented in CLAUDE.md |
| components/layouts/app/sidebar/profile-card.blade.php:108 | locale switch (`$localized`) | same |
| profile-card:160, layout/header:177 | logout form POST | POST, session ends |
| layout/header:51,58,64 | Google doc, hcsyria.org, Google form | external, `target=_blank` |
| team-members-card:98,104,111; show-digital-id:269-299; manage-digital-id:60; employee-profile:87,94,254; profile-drawer:137; access-management/index:177,499 | tel / mailto / telegram / linkedin | non-page schemes or external |
| forms/file-input:76 | PDF preview | `target=_blank`, file |
| notifications/index:33, dropdown:30 | notification `href` | `wire:click.prevent` handler does a deliberate full redirect (section 3); mailto uses full load |
| contract/_export-pdf-state:17,88,95 | contract PDF export | file download, `window.open`/anchor click |
| position/org-chart:29 | PNG download | file download |
| archived-files-module:140,293,296 | preview img/iframe | media, not navigation |
| errors/{401,403,404,419,500,503}.blade.php:25 | back to `/` | standalone error pages rendered outside the Livewire layout; a full load also clears broken page state |
| partials/head.blade.php, mail templates, pagination vendor views | assets / email / pagination | not in-app navigation (pagination is Livewire-driven) |
| `href="#"` in vendor/livewire-table (columns/filters/selection/header) | `wire:click.prevent` actions | not navigation |

## 6. Remaining items, not changed

| file:line | link | behavior | reason |
|---|---|---|---|
| components/layouts/employee-form.blade.php:11 | `href` passed to `<x-tab.item>` | OPEN (inert) | tab-item renders a `<button wire:click>`; `href` is ignored, nothing navigates. Cosmetic leftover, not a navigation gap |
| components/layouts/app/header.blade.php:11 | `https://flowbite.com` + `#` links | OPEN (dead) | template is not referenced from any view; recommend deleting separately |
| components/layouts/app/sidebar/profile-card.blade.php:181,185 | guest login/register | FIXED | `wire:navigate` added |
| welcome.blade.php | marketing links | not reviewed | not routed |
| resources/js, public/js | no `location.href` / `location.assign` / `window.location` navigation found | n/a | only `window.open` for PDF export and anchor-click downloads |

## 7. Lifecycle notes (static check only)

Re-init on `livewire:navigated` exists in: resources/js/app.js:128, scatter-chart.js:88, charts/lazy-charts.js:82, product-tour.js:142; password-toggle.js is delegated from `document`; feedback.js keeps toasts on `window` across navigation. Select2, pickers, Swiper, tags: not re-verified in a browser. Pages reached via the newly navigating redirects (contracts show/renew, positions/programs/centers/news index) are the ones to include in the browser pass.

## 8. Changes made in this task

Livewire redirects (10 files), table link HTML (2 files), guest links in profile-card (1 file), this document. See git diff.
