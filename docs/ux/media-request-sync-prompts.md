# Prompts: bring media-request's 2026-10-29 updates into HopeHR

Status: prompts 1–4 were already run on 2026-10-29 by four parallel agents (uncommitted, on `enhance-from-media-request`). Keep them to re-run or to apply the same updates elsewhere (e.g. HC-Hub). Prompt 5 is the remaining work.

Paste the **Shared context** from `dashboard-navigation-prompts.md` first, then one prompt below.
Prompts 1–4 touch separate files and can run in parallel in the same folder; prompt 5 runs alone, last.

---

## Prompt 1: Dashboard grid behaviours

```text
<goal>
Port media-request's new home-screen behaviours into HopeHR's customisable dashboard.
</goal>

<scope>
ONLY: resources/js/dashboard/*, resources/css/dashboard.css, livewire/dashboard/index + header (view and component), livewire/dashboard/partials/tile.blade.php, app/Support/Dashboard/DashboardLayout.php, app/Livewire/Concerns/HasDashboardLayout.php + IsDashboardTile.php, components/dashboard/*, resources/lang/{en,ar}/dashboard.php, tests/Feature/Dashboard/* (not clock tests), tests/js/dashboard-layout.test.mjs.
</scope>

<port>
Read each with `git -C C:/laragon/www/media-request show <hash>`. Take the behaviour, write it in HopeHR's Tailwind/Livewire style and identity tokens.
- d32e9d7  empty tiles fold to the bottom as one closed line; Customise shows them whole
- 46952db  click outside ends Customise
- fea156e  double-click a tile that has a page to open it (only pages in the role's own menu)
- 591021b  a shortcut made big becomes a small card with its data (re-check allowed() on every load)
- 30ca296  tiles shrink down to an icon that opens them
- 1ce880d  Customise shows exactly what will be saved (hand-set sizes are kept, not grown)
- 934202d  the page scrolls over tiles that have nothing to scroll
- 662bcae + 32c7d37  a small tile or shortcut opens its card in a steady side panel (bottom sheet on phones)
- b183c0c  the late strip and the decisions become tiles (move, resize, pin, hide)
- 88a5e75  dashboard part only: phones get a light home screen (no slideshow)
Skip HC_PACK and slideshow-specific code.
</port>

<rules>
Empty state is marked with data-tile-empty on the card's empty branch. The side panel is a named dialog with Escape and focus return. Hidden, folded or icon tiles never query data. Scope and allowed() unchanged. RTL, 44px targets, reduced motion, identity colours only.
</rules>

<verify>
php artisan test --filter=Dashboard ; node --test tests/js/dashboard-layout.test.mjs ; pint on changed PHP only. Report what needs a browser check.
</verify>
```

---

## Prompt 2: Workday clock and who is online

```text
<goal>
Upgrade the clock tile: progress through the viewer's working day, and who in their scope is online.
</goal>

<scope>
ONLY: app/Livewire/Dashboard/ClockCard.php + view, app/Support/Dashboard/WorkdayClock.php, resources/js/dashboard/clock.js, new Presence support/middleware/component/migration, resources/lang/{en,ar}/dashboard_clock.php, tests/Feature/Dashboard/Clock*Test.php and Presence*Test.php. One additive middleware line in bootstrap/app.php if needed.
</scope>

<port>
2c04234 (big clock: working day + who is online), c006766 (working day from work settings), 88a5e75 clock part (phones: one-row digital clock even when analogue is chosen).
</port>

<hr_adaptation>
- The working day comes from the viewer's active contract schedule (extend WorkdayClock, don't duplicate). Show a progress bar, "At work for 2:15" / "Not started yet" / "Your day is over", the hours, and the official holiday name. Breaks are not counted.
- Online: users.last_seen_at, written at most once a minute by middleware, a failed write never fails the request. Show people seen in the last 5 minutes, never the viewer.
- STRICT scope: the same scope as the who-is-out tile (team for staff and managers, city for city HR, all for HR-SYR and leadership). Out-of-scope users are never queried.
- Refresh with wire:poll.visible.60s, only on tiles big enough to show the list. No green dot (identity has no green).
</hr_adaptation>

<verify>
Tests: scope per role, 5-minute window, throttled write, day states with Carbon::setTestNow (before, during, after, day off, named holiday en/ar, split day). php artisan test --filter=Clock and --filter=Presence ; pint.
</verify>
```

---

## Prompt 3: App-style phone bottom bar

```text
<goal>
Make the phone bottom bar app-style: a raised + in the middle and a More sheet.
</goal>

<scope>
ONLY: livewire/layout/bottom-nav.blade.php, app/Livewire/Layout/BottomNav.php, app/Support/BottomBar.php, BottomBarPicker (component and view), resources/lang/{en,ar}/mobile.php, tests/Feature/BottomNavTest.php.
</scope>

<port>
5814c31 (app-style bar with raised + and a More sheet).
</port>

<hr_adaptation>
- Order: shortcut 1, shortcut 2, raised +, shortcut 3, shortcut 4, More. The user's saved 4 shortcuts keep working.
- + opens a "New" sheet from Navigation::newActions(), each item only if permitted. No + when the role can start nothing.
- More opens a sheet with My profile, the Work inbox with its count, then every area of the role's menu.
- Both sheets: dialog, aria-modal, focus trap and return, Escape, backdrop, swipe down, inert page, scroll lock, safe-area inset, 44px targets, RTL mirrored, md:hidden.
- Red: the + and the inbox count (it waits on the viewer). Everything else neutral.
- Give the page enough bottom padding that the raised + never covers content (resources/css/app.css, body:has(.app-bottom-nav) #main-content).
</hr_adaptation>

<verify>
php artisan test --filter=BottomNav ; pint.
</verify>
```

---

## Prompt 4: Quiet hints and typed examples

```text
<goal>
Port media-request's quiet hints and self-typing placeholder examples into HopeHR forms.
</goal>

<scope>
ONLY: components/forms/*, new resources/js/typewriter.js (one import line in resources/js/app.js), new resources/lang/{en,ar}/examples.php, the leave form, employee name and contact fields, the news form, and the global search placeholder. Tests in tests/Feature/FormHintsTest.php.
</scope>

<port>
92d1304 (quiet hints, typed examples in more fields) and mrTypewriter in media-request.
</port>

<rules>
- Hint: text-2, 14px, linked by aria-describedby, always shown or shown on focus.
- Examples prop on input and textarea:
  - Types realistic examples into the placeholder only, never the value.
  - Stops on focus or typing, pauses while the tab is hidden.
  - Respects reduced motion (static first example) and works in RTL.
- Existing component APIs stay backward compatible.
- Example names must be invented AND must not match any person in test fixtures (grep tests/ first). ReleaseLinksTest scans pages for out-of-scope names, and an example name like "Lina" makes it fail.
</rules>

<verify>
php artisan test --filter=FormHints, plus the existing tests of every form touched, plus --filter=ReleaseLinks ; pint.
</verify>
```

---

## Prompt 5: Integration, browser check and leftovers (run alone, last)

```text
<goal>
Finish the media-request sync: verify the four pieces together in a real browser and close the leftovers. Report honestly what passed, what failed and what you could not check.
</goal>

<tasks>
1. npm run build, php artisan migrate (adds users.last_seen_at), then composer test. Fix every failure, or explain why it is pre-existing and unrelated.
2. Browser check (Playwright via the webapp-testing skill, or the in-app browser) at 375px and 1440px, EN and AR, light and dark:
   - dashboard customise (drag, resize to icon, pin, hide, click outside to finish)
   - an empty tile folding to the bottom line
   - side panel open/close and focus return
   - double-click opening a page
   - clock progress bar and online list
   - phone bar: + sheet, More sheet, swipe down
   - typed examples in the leave form and the search box
   Screenshot each and list every visual defect.
3. Leftovers:
   - Move the phone one-row clock rule from the @assets block in clock-card.blade.php into dashboard.css / home-screen.js, so the packer knows the real height.
   - Decide with me: hide statistics chart tiles on phones by default?
   - Remove the now-unused navigation.bottom-bar.* strings (more, more-label, lead, place, more-place) after grepping that nothing uses them.
   - Old dashboard cards use text-[10.5px] for their empty text: use the identity hint size.
   - Add a "Dashboard tiles" section to DESIGN.md: sizes, icon/collapsed/panel states, the empty marker, and the red rules.
   - List every Arabic string added today (dashboard.php, dashboard_clock.php, mobile.php, examples.php) in one table for a native-speaker review. Do not change them yourself.
4. Do not commit. Give me a suggested commit split (one commit per piece) with messages.
</tasks>
```
