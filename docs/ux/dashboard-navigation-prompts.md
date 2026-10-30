# Implementation prompts: HopeHR flexible dashboard, navigation and visual identity

Reference project: `C:\laragon\www\media-request` (its visual identity is the source of truth).
Target project: `C:\laragon\www\hc-hr`.

## How to run these

- **One prompt per Claude Code session, in order.** Each prompt builds on the previous one.
- Paste the **Shared context** block first, then the phase prompt, in the same message.
- Every prompt asks for a plan first. Read it, correct it, then let it build.
- Review the diff and run the tests yourself before moving to the next prompt. Nothing is committed by the agent.
- After prompt 4, prompts 8, 9 and 10 do not depend on each other. Prompts 9 and 10 both touch `Navigation.php` and the global search, so run those two one after the other, or in separate worktrees.

| # | Prompt | Depends on |
|---|---|---|
| 1 | Visual identity: tokens, typography, dark mode | none |
| 2 | Visual identity: components | 1 |
| 3 | Dashboard engine: backend | none (best after 2) |
| 4 | Dashboard engine: frontend | 3 |
| 5 | Clock tile | 4 |
| 6 | Calendar tile | 4 |
| 7 | Dashboard header: headline, late band, decision card | 4 |
| 8 | New tiles | 4 |
| 9 | Navigation: favourites, recents | 3 |
| 10 | Command palette, shortcuts, mobile bar | 9 |
| 11 | Release QA | all |

---

## Shared context (paste first in every session)

```text
<role>
You are a senior TALL-stack engineer (Tailwind CSS 4, Alpine.js, Laravel 13, Livewire 4 with Volt and Flux) working on a production HR system. You write idiomatic, minimal, well-tested code that reads like the code around it, and you treat security scoping and bilingual/RTL support as correctness, not polish.
</role>

<project>
HopeHR, at C:\laragon\www\hc-hr. HR system for Christian Hope Center, a multi-city Syrian NGO.
Stack: PHP 8.5, Laravel 13, Livewire 4 (+ Volt + Flux), Tailwind CSS 4, Alpine, Vite 8 (Rolldown), MySQL (tests: SQLite :memory:).
Bilingual EN/AR with full RTL. Locale-prefixed URLs (/en, /ar).
Staff work from city offices on slow, unstable connections.
</project>

<read_before_any_change>
- hc-hr/CLAUDE.md, hc-hr/PRODUCT.md, hc-hr/DESIGN.md
- The files named in the phase prompt.
CLAUDE.md is binding EXCEPT where it describes the colour palette, greys, type sizes or status styling. For those, the visual identity below wins, and prompt 1 updates CLAUDE.md and DESIGN.md to match it.
</read_before_any_change>

<visual_identity source="C:\laragon\www\media-request\public\css\app.css">
The Hope Center identity as implemented in media-request is the single source of truth. When in doubt, open that file and copy the decision, not the CSS: hc-hr expresses it through Tailwind tokens and utilities.

Colour (light):
  brand        #BE1F24  flat red, never tinted, never a pale/pink fill
  on-brand     #FFFFFF
  ink (text)   #414042  headings, body, solid neutral fills
  text-2       #6D6E71  secondary text, labels
  text-3       #939598  placeholders, decorative icons
  line         #BCBEC0  control borders
  line-soft    #E6E7E8  card borders, dividers
  surface      #E6E7E8  quiet fills (secondary buttons, "done" chips)
  soft         #F3F3F4  page background, table header, hover
  card/field   #FFFFFF
Colour (dark), same neutrals inverted, red unchanged:
  bg #161617, soft #1E1E20, card #232325, field #1B1B1D, surface #2A2A2D, hover #2C2C2F,
  line #4A4A4E, line-soft #333336, text #E6E7E8, text-2 #BCBEC0, text-3 #939598, ink-fill #E6E7E8 / on-ink #161617.
  The logo is shown white in dark mode.
No other hue anywhere: not for statuses, charts, alerts or success messages.

Red is reserved for exactly three jobs: the primary action, the current stage / current page, and what waits on the viewer or is late. Everything else is neutral.

Status = icon + words + fill shape, never colour:
  in progress  solid ink fill, on-ink text
  done         surface fill
  needs you    ink outline (1.5px)
  stopped      dashed text-2 outline
  waiting      card fill, line outline
  late / acts  solid brand red tag
  urgent       brand red outline tag

Type: Somar Sans 400/500/600/700/800.
  body 16px / 1.55 (English), 17px / 1.7 (Arabic, html[lang="ar"])
  tables 15px, table headers 13px bold text-2, hints 14px
  h1 clamp(26px, 4vw, 34px) weight 800, text-wrap: balance; card titles 18px weight 800
  numbers: font-variant-numeric: tabular-nums
Shape: radius 8px controls, 12px containers, 999px chips/avatars. Borders 1.5px on controls and chips, 1px on cards.
Elevation: shadow-sm 0 1px 3px rgba(65,64,66,.08); shadow 0 6px 24px rgba(65,64,66,.14).
Focus: 2px solid ink outline, 2px offset (not red). Inputs on focus: ink border + 3px ring rgba(65,64,66,.12).
Touch targets: 44px minimum (inputs 46px).
Buttons: primary = red fill; outline = card fill + ink border; quiet = surface fill; link = underlined text.
</visual_identity>

<non_negotiables>
- Security: city scoping goes through the existing scopes (Position::scopeInCity, the role/city helpers). Never show a record outside the viewer's scope. Pay figures never reach a browser unless User::canViewSalary() is true; super-admin is salary-blind by design.
- Every visible string goes through resources/lang/en and resources/lang/ar, complete in both. Use trans_choice for counts (Arabic has six plural forms).
- Logical properties only (ms/me/ps/pe/start/end, inset-inline). Anything directional (arrows, drag, carousels) must be verified in RTL.
- Accessibility: visible focus, real labels, dialogs with aria-modal and focus management, Escape closes, prefers-reduced-motion respected, 4.5:1 text contrast in both themes.
- Performance: nothing hidden is queried; prefer Livewire lazy; eager-load to avoid N+1.
- No new runtime dependency from a CDN. npm packages are pinned to an exact version.
</non_negotiables>

<working_method>
1. Read the listed files. Then reply with a short plan: files to create/change, data flow, open questions. Wait for my go-ahead before writing code.
2. Make the smallest change that fully meets the acceptance criteria. Match the surrounding code's naming, comment density and idioms. No speculative abstractions.
3. Write Pest/PHPUnit feature tests in tests/Feature for every new behaviour, including the permission/scope cases.
4. Finish with: php artisan test --filter=<new tests>, then composer test, then ./vendor/bin/pint, then npm run build. Fix what fails.
5. Do not commit. End with: what changed (file list), how it was verified, what was NOT verified (e.g. visual check in a browser), and any decision you made on my behalf.
</working_method>
```

---

## Prompt 1: Visual identity — tokens, typography, dark mode

```text
<goal>
Re-base HopeHR's design tokens on the Hope Center identity from media-request, and add a real dark mode. Done means every existing screen picks up the new neutrals, type scale and dark theme through tokens alone, with no per-page hacks.
</goal>

<read_first>
- media-request/public/css/app.css lines 1–80 (tokens, dark mode, base type), and resources/views/components/layouts/head.blade.php + layouts/app.blade.php (pre-paint theme script, theme toggle)
- hc-hr/resources/css/app.css (the @theme block around line 180, the neutral ramp, @custom-variant dark at line 119)
- hc-hr/resources/css/tokens.css (if it is not imported, decide whether to activate it or fold it into app.css, and say why)
- hc-hr/resources/css/palette-lock.css if it exists on the current branch. If it does not, see commit f26774d7 ("Allow only the brand identity colours across the whole app") for the approach.
- hc-hr/resources/js/charts/brand-colors.js, chartjs-brand.js, amcharts-brand.js
- hc-hr/resources/views/partials/head.blade.php, livewire/layout/header.blade.php
- hc-hr/resources/fonts (check which Somar Sans weights exist as WOFF2; 800 is needed)
</read_first>

<tasks>
1. Brand variables. Keep the existing token NAMES so the ~500 views keep working, and change their values to the identity:
   --color-primary          -> #BE1F24 (the branch may still hold #c2151c; replace it everywhere, including hard-coded copies: grep for c2151c and a51218 across resources/, app/ and config/, but leave the contract PDF and mail templates to the check below)
   --color-secondary-dark   #58595b -> #414042
   --color-accent / accent-content -> the ink token, not a raw hex
   --color-secondary-medium #6d6e71 (unchanged)
   --color-secondary-light  #939598 (unchanged)
   --color-secondary-soft   #dcddde -> #E6E7E8
   Add: --color-secondary-line #BCBEC0 and --color-secondary-mist #F3F3F4.
   Rebuild the neutral ramp so every step lands exactly on an identity neutral, with no color-mix in between:
   50 #F3F3F4, 100 #E6E7E8, 200 #E6E7E8, 300 #BCBEC0, 400 #939598, 500 #939598, 600 #6D6E71, 700–950 #414042.
   --color-primary-hover follows media-request (same red at 90% opacity is acceptable; a darker mix is not part of the identity).
2. Semantic tokens on top, used by all new work from prompt 2 on: ink, text-2, text-3, line, line-soft, surface, soft, card, field, hover, ring, backdrop, on-ink, shadow-sm, shadow. Expose them as Tailwind utilities (bg-card, text-ink-2, border-line-soft, …).
3. Dark mode. Define the dark values of every semantic token. It follows prefers-color-scheme unless <html data-theme="light|dark"> overrides it. Make @custom-variant dark match both the media query (when no data-theme) and data-theme="dark", so existing dark: utilities work.
   - A tiny inline script in head applies the stored choice before first paint and re-applies it on livewire:navigated. localStorage access wrapped in try/catch.
   - A toggle button in the header next to the language switch: aria-pressed, translated label, moon/sun icon, 44px.
   - meta theme-color for both schemes. Logo rendered white in dark (filter, as media-request does).
   - Charts read only the brand variables; make brand-colors.js re-read them when the theme changes.
4. Palette lock: every Tailwind/Flowbite/Flux hue family (red, rose, green, emerald, amber, yellow, blue, sky, indigo, …) must resolve to brand values, so a stray bg-green-600 can only paint an identity neutral. If palette-lock.css exists, re-point it at the new neutrals; if not, restore it (red/rose 50–200 -> neutral, 300–600 -> primary, 700+ -> primary-hover; every other hue -> the neutral step of the same number) and import it after Flowbite's theme.
5. Typography: body 16px/1.55 EN, 17px/1.7 AR; table text 15px; table headers 13px; hints 14px; h1 clamp(26px,4vw,34px)/800 with text-wrap: balance. Ensure Somar Sans 800 is available as WOFF2 in resources/fonts and preloaded only if used above the fold. Tabular numbers utility.
6. Focus: replace any red focus ring with the identity focus (2px ink outline, 2px offset; ink border + 3px ring on inputs).
7. Documentation: rewrite CLAUDE.md "Brand / theming" and DESIGN.md (front-matter colours, typography, components) to the identity above, stating that media-request is the reference: the palette, the "no other hue" rule and the palette lock, the status rule (icon + words + fill shape), type sizes and focus style. No #c2151c or #a51218 may remain.
</tasks>

<out_of_scope>
Restyling individual components (prompt 2). PDF contract and email templates must NOT change: verify they do not consume the tokens you edited, or pin them.
</out_of_scope>

<acceptance_criteria>
- Dashboard, employee list, employee edit, leave form, contract composer, recruitment pipeline and login look correct in light and dark, EN and AR, at 375px and 1440px.
- No white flash on load or on wire:navigate in dark mode.
- No hue other than the brand red and identity neutrals renders anywhere (spot-check with palette-lock disabled locally, then re-enable).
- Text contrast >= 4.5:1 for body and >= 3:1 for large text and UI borders, in both themes. List the pairs you checked.
- Contract PDF output is byte-for-byte unaffected in styling (render one before/after).
- Existing test suite passes.
</acceptance_criteria>
```

---

## Prompt 2: Visual identity — components

```text
<goal>
Bring HopeHR's shared components in line with the media-request identity, so statuses, buttons, inputs, tables and empty states look and behave the same across every module. Done means one status component and one button set are used everywhere, and no view hand-rolls its own.
</goal>

<read_first>
- media-request/public/css/app.css sections: buttons, chips and tags, forms, lists/tables, page (.card, .empty, .ph)
- media-request/resources/views/components/media/status.blade.php and progress.blade.php
- hc-hr/resources/views/components/badge.blade.php
- hc-hr/resources/views/livewire/employee/leave/partials/card-status.blade.php (tells statuses apart by emerald/amber hue, which the identity forbids; once the palette lock maps those to grey, "accepted" and "pending" become almost identical)
- hc-hr enums that expose classes()/dotClasses()/color (grep for "function classes" and "function dotClasses" in app/Enums), e.g. RecruitmentInterviewStatus
- hc-hr/resources/views/components/forms/* , components/card.blade.php, center-empty-state.blade.php, confirm-dialog.blade.php
- the livewire-tables theme/views used by EmployeeTable
</read_first>

<tasks>
1. One status component: <x-status :tone="..." :icon="..." :label="..." /> with tones work | done | attn | stop | wait, plus <x-tag variant="act|late|rush|soft">. Exactly the identity rules: icon + words + fill shape, red only for act/late/rush.
2. A StatusTone contract: every status enum (leave, contract, recruitment request/application/interview/offer, employee) implements tone(), icon() and label() through one interface or trait. Map each case deliberately and show me the full mapping table in your plan before coding. Remove the old classes()/dotClasses()/color paths once nothing uses them.
3. Replace every status pill in views with the new component (grep for emerald-, amber-, green-, yellow-, blue-, bg-{$ and the old partials). Report the count replaced per module.
4. Buttons: one <x-button variant="primary|outline|quiet|link" size="sm|md|lg"> with loading state (spinner + aria-busy, disabled while wire:loading). Re-skin Flux buttons through Flux's theming hooks rather than overriding each usage.
5. Inputs, selects, textareas, checkbox/radio cards, segmented control: identity borders (1.5px line), hover text-2, focus ink + ring, invalid = 2px brand border + error line with icon. Keep all existing component APIs unchanged.
6. Tables (livewire-tables theme): 15px body, soft header with 13px bold text-2, row hover, and below 820px the stacked-card layout with data labels, as media-request does.
7. Card, page header (title + lead + actions), empty state (dashed border, icon, bold line, one action), confirm dialog: identity versions.
8. A one-page component sheet at /en/_ui and /ar/_ui (local and testing environments only, behind auth) showing every component in every state. It is the visual regression reference for the remaining prompts.
</tasks>

<acceptance_criteria>
- Every status in the app is distinguishable without colour (check in greyscale) and has a text label.
- No view contains emerald-/amber-/green-/yellow-/blue- classes (add a test that greps resources/views and fails if one appears).
- Component APIs used by existing views still work; the suite passes.
- The _ui page renders in both themes and both directions, and returns 404 in production.
</acceptance_criteria>
```

---

## Prompt 3: Dashboard engine — backend

```text
<goal>
Build the data layer for a customisable dashboard: each user arranges, resizes, pins and hides tiles, per active role. Backend only in this session; no UI.
</goal>

<read_first>
- media-request/app/Support/MediaRequests/DashboardLayout.php (whole file; port its design)
- media-request/app/Livewire/MediaRequests/Dashboard.php (record(), saveLayout(), resetLayout()) and the media_dashboards migration
- hc-hr/app/Support/Navigation.php (for(), flatten(), the "new" menu around lines 230–250)
- hc-hr/app/Livewire/Dashboard/*.php and resources/views/livewire/dashboard/index.blade.php (every current card and the permission/scope rule it uses today)
- hc-hr User::myRole() and the role switcher
</read_first>

<tasks>
1. Migration dashboard_layouts: id, user_id (FK, cascade), role (string, the active role normalised like activeRoleIs: any HR-* -> its own value, do not collapse cities), layout (json, nullable), timestamps; unique (user_id, role).
2. Model with a User relation.
3. App\Support\Dashboard\DashboardLayout, a port of the media-request class:
   - WIDGETS: key => [icon, min [cols,rows], max [cols,rows], default [cols,rows], shownByDefault]. One entry per current card: for-you, my-leaves, notes, team, news, calendar, upcoming-leaves, today-events, upcoming-events, pending-requests, checklist, badges, soon-ending-contracts, stats-department, stats-job-level, stats-contract-type, stats-no-leaves, stats-salary. Add clock now (built in prompt 5).
   - APPS: generated, not hand-written, from Navigation::flatten() for the user, so every page the user may open can be a 1x1–3x3 shortcut tile; plus the "new" actions from the Navigation new-menu. Shortcut keys are stable ("app.<nav id>").
   - allowed(User, key): the SAME permission and city-scope rule each card uses today. stats-salary also requires canViewSalary(). Do not invent permissions.
   - defaults(User): a default layout per role family (employee, team manager, city HR, HR-SYR, leadership/super-admin) that reproduces today's dashboard order as closely as possible, so nobody is surprised on first load.
   - clean(User, ?array): drop unknown, disallowed and duplicate keys; clamp sizes to bounds; append newly added catalogue items (hidden if not shown by default); pinned first; cap at 60 items. LOOKS for multi-look tiles (clock: digital|analog).
4. A Livewire concern (e.g. App\Livewire\Concerns\HasDashboardLayout) providing #[Renderless] saveLayout(array $items) and resetLayout(). Validate the payload shape strictly (key string, size "CxR", pinned bool, hidden bool, style string). Create the row on first save only.
</tasks>

<tests file="tests/Feature/Dashboard/DashboardLayoutTest.php">
- An employee cannot get HR tiles, the salary tile, or shortcuts to pages they cannot open, even by posting them to saveLayout.
- super-admin never gets stats-salary.
- Out-of-bounds sizes are clamped; unknown and duplicate keys dropped.
- Layouts are stored per role; switching role returns that role's layout.
- A tile added to the catalogue later appears for a user whose layout was saved before it existed.
- A shortcut disappears when the user loses access to its page.
</tests>
```

---

## Prompt 4: Dashboard engine — frontend

```text
<goal>
Turn the dashboard page into a customisable tile grid driven by DashboardLayout (prompt 3), with the media-request home-screen interaction model, built in hc-hr's Tailwind/Livewire style.
</goal>

<read_first>
- media-request/public/js/home-screen.js (whole file)
- media-request/resources/views/livewire/media-requests/dashboard.blade.php: lines 1–60 (header, customise controls), 125–200 (tiles), 430–460 (gallery)
- the "home screen (phone style)" section of media-request/public/css/app.css
- hc-hr/resources/views/livewire/dashboard/index.blade.php, app/Livewire/Dashboard/Index.php, livewire/dashboard/card-placeholder.blade.php
- hc-hr/resources/js/app.js, resources/js/charts/lazy-charts.js, vite.config.js
- hc-hr/resources/views/components/tour/* (data-tour anchors on the dashboard)
</read_first>

<tasks>
1. Port home-screen.js to resources/js/dashboard/home-screen.js as an ES module loaded only on the dashboard route. Add sortablejs from npm at an exact version. Keep: long-press or "Customise" to edit, brief wiggle, faint cell guides while editing, drag to move, corner handle to resize cell by cell with a "2×3" label, arrow-key resize on the focused handle, FLIP glide on reflow, background save via $wire.saveLayout. Verify drag and resize directions in RTL.
2. Grid: 12 columns >= 1600px, 8 on desktop, 4 on phones; a tile wider than the grid spans it. Tiles use container queries so their content adapts to their own size, not the viewport.
3. Header controls: "Customise". In edit mode: "Reset" (confirm dialog), "Add" (gallery of hidden tiles, disabled when none), "Done". On each tile in edit mode: hide (×), pin, resize handle; all 44px and labelled.
4. Each widget tile is a lazy Livewire component with the existing card-placeholder. Hidden tiles are not rendered and their data is never queried. Charts keep using lazy-charts and resize via ResizeObserver when the tile changes size.
5. Shortcut tiles: icon, label, optional counter (e.g. inbox count from Navigation), wire:navigate link; grow to 3×3 with a larger icon.
6. Failure isolation: a tile whose data fails shows x-ui.load-error inside the tile and never breaks the grid (keep the failedSections behaviour).
7. Remove the legacy wrapper noise in index.blade.php (text-white font-semibold, fixed h-70 heights). Keep every data-tour anchor on its tile and update the tour if a step targets something that moved.
8. Style strictly with the identity tokens from prompts 1–2: card fill, line-soft border, 12px radius, shadow-sm; edit-mode guides use the soft/line tokens.
</tasks>

<acceptance_criteria>
- A user who never customised sees a dashboard equivalent to today's.
- Move/resize/hide/pin/add/reset work with mouse, touch and keyboard, in EN and AR.
- Reload keeps the layout; switching role shows that role's layout.
- Network panel: no request for hidden tiles; saving does not re-render the page.
- prefers-reduced-motion disables wiggle and glide.
- tests/Feature/DashboardTest.php and tests/Feature/Dashboard/* pass; add a Livewire::test for saveLayout/resetLayout through the page component.
</acceptance_criteria>
```

---

## Prompt 5: Clock tile

```text
<goal>
Add the clock tile, ported from media-request and made useful for HR with the employee's working hours.
</goal>

<read_first>
- media-request dashboard.blade.php, the 'clock' case (around lines 257–300); the .clock and .face styles in public/css/app.css; DashboardLayout LOOKS
- hc-hr/resources/views/components/contract/schedule-clause.blade.php and how a contract stores its schedule (latestActiveContract and its schedule clause)
- hc-hr LeaveRequestPricing::holidayDates()
- hc-hr/app/Support/Dashboard/DashboardLayout.php
</read_first>

<tasks>
1. Two looks, chosen in customise mode and saved as the tile's style: digital (weekday, large HH:mm in tabular numerals, full date) and analog (SVG face with hour and minute ticks, hour/minute/second hands, plus the digital readout beside it from 3×3).
2. Always Asia/Damascus regardless of device timezone. Latin digits in both locales (ar-SY-u-nu-latn); weekday and month in the UI language.
3. HR line, only when the data exists: from the viewer's active contract schedule and the official holidays, one of "Your day starts in 1:20", "Your day ends in 2:15", "Outside working hours", "Day off today". If the schedule is unknown, show nothing. Never guess.
4. Ticks run in Alpine only, no server calls; pause on visibilitychange hidden.
5. Size-aware via container queries: 2×1 time only; 2×2 time + date; 3×3+ full look + HR line.
6. aria-label carries the time; no aria-live on the ticking value.
7. Visual: identity only. Hands and ticks in ink, second hand brand red, face on card fill.
</tasks>

<tests>
Tile is available to everyone; HR line absent without an active contract; correct line for a known schedule at fixed times (Carbon::setTestNow) including a holiday.
</tests>
```

---

## Prompt 6: Calendar tile with real data

```text
<goal>
Turn CalendarCard into a calendar tile that shows real, scope-safe HR events, and remove the sample data it shows today.
</goal>

<read_first>
- hc-hr/app/Livewire/Dashboard/CalendarCard.php (note config('samples.calendar.events'): sample data to be removed) and livewire/dashboard/calendar-card.blade.php
- hc-hr/app/Livewire/Team/Calendar.php (reuse its queries and scoping for team leave)
- hc-hr/app/Livewire/Recruitment/Interviews/Calendar.php (reuse for interviews)
- hc-hr LeaveRequestPricing::holidayDates(), tests/Feature/BirthdayHolidayTest.php, the News/Event models
- media-request dashboard.blade.php: the 'calendar' case (around lines 339–365) and the day pop-up (around 460–498)
</read_first>

<tasks>
1. App\Services\Dashboard\CalendarFeed::forMonth(User, CarbonImmutable $month): events grouped by Y-m-d, each type gated by its permission and city scope:
   official holidays (by the viewer's contract) · my leaves (accepted + pending) · my team's leaves (managers, same rule as Team\Calendar) · birthdays (in scope; no year or age) · contract ends (city HR, in scope) · interviews (recruitment permission, same rule as Interviews\Calendar) · news events.
   One query per type per month. No N+1.
2. Remove the use of config('samples.calendar.events') from the card. If anything else uses that config key, tell me and leave it.
3. Rendering: under each day, small markers distinguished by SHAPE and icon, not colour (identity): e.g. solid dot = my leave, ring = team leave, diamond = holiday, square = HR deadline. Today = solid brand-red day number. A legend appears from 6×4 up.
4. Clicking a day opens a real dialog (aria-modal, focus trapped and restored, Escape closes) listing that day's events with links, plus "Request leave for this day", which opens the existing leave form (leaves.index?new=1) with the date prefilled, only for users who may request leave.
5. Month navigation stays; add "Today" when another month is shown. Arrows mirror in RTL.
6. Size-aware: 3×3 month grid; 6×4+ month grid with an "Next 7 days" list beside it.
</tasks>

<tests>
Employee without a team never sees others' leave; city HR never sees another city's contract ends; no recruitment permission means no interviews; the dialog lists the right day; query count for a month is constant as events grow (assert with DB::getQueryLog).
</tests>
```

---

## Prompt 7: Dashboard header — headline, late band, decision card

```text
<goal>
Make the top of the dashboard answer one question: what is waiting on me right now?
</goal>

<read_first>
- media-request dashboard.blade.php lines 33–123 (.hello, .lateband, .decide), and their styles in public/css/app.css (.hello, .lateband, .decide, .dcard, .ddots)
- hc-hr/app/Livewire/Dashboard/ForYouNow.php and livewire/dashboard/for-you-now.blade.php
- the greeting section in hc-hr/resources/views/livewire/dashboard/index.blade.php
- Navigation::inbox()
</read_first>

<tasks>
1. Headline driven by state: the greeting becomes a small line above; the h1 says what waits, from ForYouNow data, e.g. "3 leave requests need your decision", "2 items in your inbox", "Nothing is waiting on you today". trans_choice with all Arabic plural forms. Job title moves to a secondary line.
2. Late band (role="alert"), shown only when something in scope is actually late: leave that started with no decision, contracts ended without renewal, interviews past their time without an evaluation. Count, first three names, one primary button "Open the oldest".
3. Decision card, replacing the For-you lists when decisions exist: one large card for the oldest decision (what, who, dates, "since 2 days"), one red button with a concrete verb ("Review leave"), prev/next arrows and dots (mirrored in RTL, arrow keys), and "Show all" which lays them side by side and is remembered in localStorage. aria-roledescription="carousel"/"slide" as in the reference.
4. For-you becomes a regular tile (key for-you). The headline, late band and decision card stay fixed above the grid and are not part of customisation.
</tasks>

<acceptance_criteria>
Every number equals what the linked page shows; nothing outside the viewer's scope appears. Tests for the headline's three states, the band appearing and disappearing, and the decision order (oldest first).
</acceptance_criteria>
```

---

## Prompt 8: New tiles

```text
<goal>
Add four small, high-value tiles to the dashboard catalogue.
</goal>

<read_first>
hc-hr/app/Support/Dashboard/DashboardLayout.php, app/Livewire/Team/Calendar.php, LeaveBalanceService, LeaveRequestPricing::holidayDates(), the birthday logic (tests/Feature/BirthdayHolidayTest.php), media-request/public/css/app.css (.stat, .av).
</read_first>

<tiles>
1. who-is-out "Out today": managers and HR in scope. Avatars, names, leave type; "+4" overflow; link to the team calendar. Empty: "Everyone is in today".
2. next-holiday: the next official holiday for the viewer's contract and a day countdown. Hidden when none within 60 days.
3. leave-balance: an SVG ring of remaining annual leave (brand red on surface track), number in the centre, "Request leave" button. Data from LeaveBalanceService only; no new calculation.
4. birthdays "Birthdays this week": in scope only, no year or age.
</tiles>

<requirements>
Each is a lazy Livewire component with bounds in DashboardLayout, shown by default only for the right role family, size-aware via container queries, fully translated, identity-styled, and covered by a scope test proving it never shows out-of-scope people.
</requirements>
```

---

## Prompt 9: Navigation — favourites and recents

```text
<goal>
Shorten the path to the pages each person actually uses.
</goal>

<read_first>
hc-hr/app/Support/Navigation.php, livewire/layout/sidebar.blade.php, components/layouts/app/sidebar/*, livewire/layout/global-search.blade.php and its component, tests/Feature/BottomNavTest.php, the dashboard_layouts table from prompt 3.
</read_first>

<tasks>
1. Favourites: a star on each sidebar item, visible on hover and on keyboard focus. Starring pins the page to a "My favourites" section at the top of the sidebar; max 8; reorderable by drag and by keyboard. Persist per user server-side; propose in your plan whether that is a new table or a column, and why. On every render, filter favourites through Navigation::flatten() so a page the user lost access to disappears.
2. Recents: record the last 8 pages and last 5 employee profiles opened, server-side so they follow the user across devices. Show them in the Ctrl+K panel when the query is empty, in two groups (Pages, People). Re-check scope on display, not only on write.
3. Collapsible sidebar sections, state remembered in localStorage; the section containing the current page is always open. Active item uses the identity rule (ink text + brand indicator).
4. Collapsed sidebar: favourites show as icons with tooltips.
</tasks>

<tests>
Cannot favourite a disallowed page; favourite disappears after permission removal; recents never show an out-of-scope employee; BottomNavTest still passes.
</tests>
```

---

## Prompt 10: Command palette, keyboard shortcuts, mobile bar

```text
<goal>
Turn the existing Ctrl+K search into a place to act, not only to find, and let people choose their mobile shortcuts.
</goal>

<read_first>
hc-hr/resources/views/livewire/layout/global-search.blade.php and its component, Navigation.php (new-menu around lines 230–250), livewire/layout/bottom-nav.blade.php and BottomNav.php, resources/js/help-panel.js, DashboardLayout APPS from prompt 3.
</read_first>

<tasks>
1. A "Commands" group in Ctrl+K results, built from the Navigation new-menu with its permissions ("New leave", "Leave for an employee", "New employee", "New contract", "New news"), plus "Toggle dark mode", "Switch language", "Customise dashboard". Matching works across both languages for every item (typing "اجازة" or "leave" finds the same command) and normalises Arabic (hamza forms, ta marbuta/ha, alef maqsura/ya, tatweel, diacritics).
2. Go-to shortcuts: g then d/e/l/c/i/r for dashboard, employees, leave, contracts, inbox, recruitment, each gated by permission. Ignored while typing in an input, textarea, select or contenteditable. "?" opens a shortcuts sheet (reuse the help panel if it fits).
3. Mobile bottom bar: the user picks 4 shortcuts from their allowed pages (same source as dashboard APPS); the 5th slot is always "More" and opens the menu. Chosen from dashboard customise mode on phones, persisted server-side.
</tasks>

<tests>
Commands respect permissions; bilingual and normalised matching (unit-test the normaliser); shortcuts ignored inside inputs (unit-test the guard function, browser test if Dusk/Playwright is available); BottomNavTest updated for custom shortcuts.
</tests>
```

---

## Prompt 11: Release QA

```text
<goal>
Prove the work from prompts 1–10 is safe to release, and report honestly what could not be proven automatically.
</goal>

<tasks>
1. Port media-request's "open every link for every kind of person" test: for employee, team manager, city HR (two different cities), HR-SYR and super-admin, open the dashboard, every allowed tile, every shortcut, every favourite and every command target; assert 200 and assert that no out-of-scope names or pay figures are present in the HTML.
2. RTL review of drag, resize, arrows, decision carousel, calendar navigation and shortcuts.
3. Accessibility: focus order, labels, dialog behaviour, contrast in both themes, reduced motion, 44px targets. Extend tests/Feature/AccessibilityTest.php.
4. Performance: for each role's default dashboard, record query count and server time per tile. Flag any tile over 5 queries or 150 ms with a concrete fix.
5. Visual: compare the /_ui component sheet and five key pages against media-request's look in both themes; list deviations.
6. Update the product tour (resources/lang/*/tour.php and data-tour anchors) for customising, favourites and Ctrl+K. Add a short "Customise your dashboard" section to docs/دليل-الاستخدام.md (Arabic) .
</tasks>

<deliverable>
A report: checked and passed; failed, with cause and fix; not checkable automatically and needing a manual pass (with exact steps).
</deliverable>
```
