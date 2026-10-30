---
name: HopeHR
description: Bilingual (EN/AR, RTL) HR system for Christian Hope Center, a multi-city Syrian NGO.
reference: C:\laragon\www\media-request\public\css\app.css (the Hope Center identity; source of truth)
colors:
  light:
    brand: "#BE1F24"
    on-brand: "#FFFFFF"
    ink: "#414042"
    ink-2: "#6D6E71"
    ink-3: "#939598"
    line: "#BCBEC0"
    line-soft: "#E6E7E8"
    surface: "#E6E7E8"
    soft: "#F3F3F4"
    hover: "#F3F3F4"
    card: "#FFFFFF"
    field: "#FFFFFF"
    base: "#FFFFFF"
    on-ink: "#FFFFFF"
    focus: "#414042"
    ring: "rgba(65,64,66,.12)"
    backdrop: "rgba(20,19,21,.45)"
  dark:
    brand: "#BE1F24"
    on-brand: "#FFFFFF"
    ink: "#E6E7E8"
    ink-2: "#BCBEC0"
    ink-3: "#939598"
    line: "#4A4A4E"
    line-soft: "#333336"
    surface: "#2A2A2D"
    soft: "#1E1E20"
    hover: "#2C2C2F"
    card: "#232325"
    field: "#1B1B1D"
    base: "#161617"
    on-ink: "#161617"
    focus: "#E6E7E8"
    ring: "rgba(230,231,232,.18)"
    backdrop: "rgba(0,0,0,.6)"
typography:
  family: "SomarSans, Lato, ui-sans-serif, system-ui, sans-serif"
  weights: [400, 500, 600, 700, 800]
  h1: { size: "clamp(26px, 4vw, 34px)", weight: 800, lineHeight: 1.2, wrap: balance }
  card-title: { size: "18px", weight: 800 }
  body-en: { size: "16px", lineHeight: 1.55 }
  body-ar: { size: "17px", lineHeight: 1.7 }
  table: { size: "15px" }
  table-header: { size: "13px", weight: 700, color: ink-2 }
  hint: { size: "14px" }
  counter: { size: "12px" }
  numbers: "font-variant-numeric: tabular-nums"
rounded:
  control: "8px"
  container: "12px"
  chip: "999px"
borders:
  control: "1.5px"
  chip: "1.5px"
  card: "1px"
elevation:
  shadow-sm: "0 1px 3px rgba(65,64,66,.08)"
  shadow: "0 6px 24px rgba(65,64,66,.14)"
focus:
  outline: "2px solid ink, 2px offset"
  input: "ink border + 3px ring"
touch-target: { min: "44px", input: "46px" }
---

# HopeHR design system

HopeHR uses the **Hope Center identity exactly as media-request implements it**. When a decision is
unclear, open `media-request/public/css/app.css` and copy the decision (not the CSS): here it is
expressed through Tailwind 4 tokens and utilities.

Where it lives:

| What | File |
|---|---|
| Colour tokens, light + dark, base type, focus | `resources/css/app.css` (`@theme` block and the two dark blocks after it) |
| Spacing, type scale, radii, elevation | `resources/css/tokens.css` |
| The "no other hue" lock | `resources/css/palette-lock.css` |
| Theme switch, pre-paint script | `resources/views/components/theme-toggle.blade.php`, `resources/views/partials/head.blade.php` |
| Chart colours | `resources/js/charts/brand-colors.js` (+ `chartjs-brand.js`, `amcharts-brand.js`) |
| Dashboard tile grid and customising | `resources/css/dashboard.css`, `resources/js/dashboard/home-screen.js` (see Dashboard tiles) |

## Colour

One flat red and the Hope Center neutrals. **No other hue, anywhere**: not for statuses, charts,
alerts or success messages.

| Token (utility) | Light | Dark | Use |
|---|---|---|---|
| `primary` | `#BE1F24` | `#BE1F24` | the three red jobs (below). Hover: same red at 90%. |
| `on-brand` | `#FFFFFF` | `#FFFFFF` | text on red |
| `ink` | `#414042` | `#E6E7E8` | headings, body text, solid neutral fills |
| `ink-2` | `#6D6E71` | `#BCBEC0` | secondary text, labels, table headers |
| `ink-3` | `#939598` | `#939598` | placeholders, decorative icons (not body text) |
| `on-ink` | `#FFFFFF` | `#161617` | text on a solid ink fill |
| `line` | `#BCBEC0` | `#4A4A4E` | control borders |
| `line-soft` | `#E6E7E8` | `#333336` | card borders, dividers |
| `surface` | `#E6E7E8` | `#2A2A2D` | quiet fills: secondary buttons, "done" chips |
| `soft` | `#F3F3F4` | `#1E1E20` | page background, table header |
| `hover` | `#F3F3F4` | `#2C2C2F` | row / item hover |
| `card` | `#FFFFFF` | `#232325` | cards, dialogs, menus |
| `field` | `#FFFFFF` | `#1B1B1D` | input background |
| `base` | `#FFFFFF` | `#161617` | top bar, sidebar |
| `focus` | `#414042` | `#E6E7E8` | focus outline |
| `ring` | ink 12% | `#E6E7E8` 18% | 3px ring round a focused input |
| `backdrop` | `#141315` 45% | black 60% | behind dialogs |

Utilities: `bg-card`, `bg-soft`, `bg-surface`, `bg-base`, `bg-hover`, `bg-field`, `bg-ink`,
`text-ink`, `text-ink-2`, `text-ink-3`, `text-on-ink`, `text-on-brand`, `border-line`,
`border-line-soft`, `ring-ring`, `bg-backdrop`, `outline-focus`.

**Red has exactly three jobs:** the primary action, the current stage / current page, and what
waits on the viewer or is late. Everything else is neutral. Red is never tinted into a pale or
pink fill (no `bg-primary/10`).

**Legacy names** keep the existing views working and land exactly on identity neutrals:
`secondary-dark` = ink, `secondary-medium` = ink-2, `secondary-light` = ink-3,
`secondary-soft` = surface, `secondary-line` = line, `secondary-mist` = soft, `accent` = ink,
and the neutral ramp `50 #F3F3F4 · 100–200 #E6E7E8 · 300 #BCBEC0 · 400–500 #939598 ·
600 #6D6E71 · 700–950 #414042`.

### Palette lock

`palette-lock.css` (imported after Flowbite's theme) redefines every Tailwind, Flowbite and Flux
colour family in terms of the brand: red and rose 50–200 → neutral 50–200, 300–600 → primary,
700–950 → primary-hover; every other hue → the neutral step of the same number. A stray
`bg-green-600` or a runtime-built `bg-{$color}-100` can therefore only paint an identity colour.
That is a safety net, not a design tool: choose colour deliberately from the tokens above.

## Dark mode

Follows `prefers-color-scheme` unless `<html data-theme="light|dark">` overrides it. The header
switch (`<x-theme-toggle>`, a row in the account menu on phones) stores the choice in
`localStorage` (`hopehr-theme`); an inline script in `partials/head.blade.php` applies it before
first paint and keeps it across `wire:navigate`.

Dark redefines **the same variables**: the neutrals invert, the red stays. The legacy neutral ramp
flips with them, so a view written only with light utilities (`bg-white`, `text-neutral-800`,
`border-neutral-200`) is dark without changes. Consequences:

- Never write `bg-white dark:bg-neutral-800` pairs. Use `bg-card` and it flips by itself. The
  `dark:` variant (system setting or `data-theme="dark"`) is for what a token cannot express.
- A solid `bg-neutral-800`-style fill becomes a light ink fill in dark mode; its `text-white` is
  switched to `on-ink` for you. New work writes `bg-ink text-on-ink`.
- The logo (`<x-app-logo>`, class `app-logo`) is shown white in dark mode.
- Shadows turn black in dark mode.

## Status

**Icon + words + fill shape, never colour.** Every status must be readable in greyscale.

| Status | Shape |
|---|---|
| in progress | solid ink fill, on-ink text |
| done | surface fill |
| needs you | ink outline (1.5px) |
| stopped | dashed ink-2 outline |
| waiting | card fill, line outline |
| late / acts | solid brand red tag |
| urgent | brand red outline tag |

## Typography

Somar Sans 400/500/600/700/800 (WOFF2 in `resources/fonts/SomarSans`; Regular and Medium are
preloaded). Lato is the fallback.

| Role | Size | Notes |
|---|---|---|
| body (English) | 16px / 1.55 | `text-body` |
| body (Arabic) | 17px / 1.7 | `html[lang="ar"]` redefines `--text-body` |
| table cells | 15px | `text-table` |
| table headers | 13px bold, ink-2 | `text-th` |
| hints, helper text | 14px | `text-small` / `text-hint` |
| counters, badges | 12px | `text-caption`; never smaller |
| h1 | clamp(26px, 4vw, 34px), 800 | `text-wrap: balance` |
| card titles | 18px, 800 | `text-h4 font-extrabold` |
| numbers | tabular | `num` (also isolates the run in RTL) or `tabular-nums` |

## Shape and elevation

- Radius: 8px controls (`rounded-control`), 12px containers (`rounded-surface`), 999px chips and
  avatars (`rounded-round`).
- Borders: 1.5px on controls and chips, 1px on cards.
- Elevation: `shadow-sm` / `shadow-e1` = 0 1px 3px rgba(65,64,66,.08); `shadow` / `shadow-e3` =
  0 6px 24px rgba(65,64,66,.14); `shadow-e2` for dropdowns in between.

## Focus and touch

- Focus: 2px solid ink outline, 2px offset — **not red**. Inputs on focus: ink border + 3px ring
  (`ring` token). Both are unlayered rules in `app.css`, so they override older
  `focus:*-primary` utilities.
- Touch targets: 44px minimum, inputs 46px.

## Components

Classes in `resources/css/components.css`; every component in every state is on the component
sheet at `/en/_ui` and `/ar/_ui` (local and testing only).

| Component | Use | Props |
|---|---|---|
| `<x-status>` | every status, everywhere | `status` (a `HasStatusTone`), or `tone` (work, done, attn, stop, wait) + `icon` + `label` (or slot) |
| `<x-tag>` | a fact beside a status or title | `variant` (act, late, rush = red; soft = surface), `icon`, `label` (or slot) |
| `<x-button>` | every new button or button-link | `variant` (primary, outline, quiet, link), `size` (sm, md, lg), `href`, `type` (button), `icon`, `full`, `loading` (true), `target` |
| `<x-page-header>` | page title row | `title`, `lead`; slots `crumb`, `actions` |
| `<x-card>` | a titled block | `title`; slot `icon`; body is the slot |
| `<x-center-empty-state>` | empty / filtered / error | `variant`, `title`, `description`; slots `icon`, `action` |
| `<x-ic>` | line icons | `name` (check, x, clock, play, pause, pen, alert, warning, info, ban, minus, flame, bolt, user, users, eye, calendar, calendar-x, box, return, inbox, shield, star, plus, trash, question, search, check-circle, pin, layout, reset, cake, file, dot) |

- Status enums implement `App\Support\Status\HasStatusTone` (`tone()`, `icon()`, `label()`):
  `LeaveRequestStatus` (`::of($value)`), `EmployeeStatus::for($employee)`,
  `ContractStatus::for($contract)`.
- Buttons: primary = red fill; outline = card fill + ink border; quiet = surface fill; link =
  underlined text. 44px tall (sm is 38px with a fine pointer, 44px on touch). While Livewire works
  on the button's own `wire:click` method, it is disabled, `aria-busy` and shows a spinner. The
  legacy `<x-primary-button>`, `<x-secondary-button>` (= quiet), `<x-outline-button>` and
  `<x-primary-link>` keep their APIs and render the same classes. `<flux:button variant="primary">`
  is re-skinned through Flux's accent variables.
- Fields (`<x-forms.*>`): 46px, 1.5px line border, field fill; hover ink-2; focus ink + 3px ring;
  invalid (`aria-invalid="true"`) = 2px brand border plus the error line (`.hc-err`: red, 14px
  bold, alert icon). Checkboxes and radios are ink when checked; checkbox cards and the
  segmented control show the choice as ink border + surface fill, never red.
- Cards: card fill, 1px line-soft border, 12px radius, shadow-sm, 18px/800 title.
- Empty state: dashed line border, ink-3 icon, bold ink line, one action.
- Tables (livewire-tables theme): 15px body, soft header with 13px bold ink-2 labels, hover row,
  no striping. Below 820px a table without its own phone cards becomes stacked cards, each cell
  led by its column name (`data-label`).
- Notes and warnings: `border-s-4 border-ink bg-soft` with a `warning` icon, never amber.

## Dashboard tiles

The dashboard is a home screen of tiles the person arranges, per active role
(`App\Support\Dashboard\DashboardLayout`; browser side `resources/js/dashboard/home-screen.js`,
its own Vite entry, with SortableJS 1.15.7 fetched on the first Customise; styles
`resources/css/dashboard.css`).

- **Grid**: 12 columns once the grid is 1200px wide (a ~1600px screen with the sidebar open),
  8 on a computer, 4 below 760px (phones, square cells). Row height
  `clamp(88px, 0.75 × cell width, 116px)`, gap 14px (12px on phones). A tile wider than the grid
  takes its full width. At rest gaps are filled (packing); while customising, tiles flow in order.
- **A tile** is the grid cell `.hc-tile` (`data-key`; `data-cols` / `data-rows` = the size it is
  shown at; `data-look` for a tile with looks). The card sits in `.hc-tile-body`, clipped to the
  tile and stretched to fill it. Every tile is a **size container named `tile`**
  (`container: tile / size`), so a card adapts to its own size, never the screen's: in CSS
  `@container tile (max-height: 140px) { … }`; for width also Tailwind's `@sm/tile:`-style
  variants. Use `data-cols` / `data-rows` only when a look is tied to an exact cell count.
- **Size thresholds** (px, from the grid's real metrics, so they hold on every screen):
  height — one row < 140, two rows 140–245, three ≥ 245, four ≥ 340;
  width — narrow (two cells or fewer) < 300, medium 300–560, wide ≥ 560.
- **Sizes** are `CxR` cells. `WIDGETS` gives each widget `min` (its *card* size: the smallest it
  can show itself at), `max` and `default`; `bounds()` lets every widget go down to 1×1, and
  below its card size it is an icon. A shortcut is 1×1 to 4×4 and from 2×2 shows its data
  (`AppCard`). At rest `layout-math.js` `pack()` places tiles in order and grows cards into the
  empty cells beside and below them, never past `max`; a size set by hand (`fixed`) is shown
  exactly as set. Only what is shown changes, never the saved size. Customise starts from the sizes seen (`bake()`),
  except where the grid cut them down: on a phone, or a grid narrower than the saved width, the
  saved size stays (`bakedSize()`), so a phone never overwrites a computer's sizes.
- **Looks**: `DashboardLayout::LOOKS` (`clock => [digital, analog]`, the first is the default),
  saved as the item's `style`, drawn as `data-look`, switched by the look button while
  customising. **On a phone** the clock is always one row and digital, whatever its saved size and
  look (`dashboard.css` "phone clock"); the packer is told (`phoneView`, `PHONE_ONE_ROW`), so the
  tiles below close up under it. A phone does not draw `PHONE_HIDDEN` (the today's-events slideshow).
- **States at rest** (none of them while customising: there every tile is shown whole):
  - *Card*: the widget itself.
  - *Icon* (`.mini`, smaller than its card size; every 1×1 shortcut): icon + name. One click
    opens the card in the **side panel**: the tile body itself as a `popover="manual"` in the top
    layer, so the Livewire card is never moved or mounted twice; along the inline-end edge, and
    **a sheet from the bottom on phones** (≤ 760px, `min(78dvh, 640px)`, 20px top corners). ×,
    Escape or the same icon closes it and focus returns to the icon.
  - *Folded* (`.closed`): a card with nothing in it right now (and not pinned: a pinned tile stays
    in place and shows its empty state) goes to the bottom as **one line**,
    one row high, at most four cells wide (`closedSize`), never grown taller: icon, name, and the
    card's own words. Its card says so with **`data-tile-empty`** on its empty-state element
    (`data-state="empty"` and `.hc-nt-empty` also count); the line shows that element's h2/h3 (else its
    text), or `dashboard.home.nothing` when there is none or it runs past 80 characters. Put the marker only
    on a real "nothing here" state, never on loading or a load error. The grid re-reads it on every
    change (lazy load, polling, retry), so a card that fills up unfolds by itself.
- **Lazy loading**: every widget is mounted `lazy`, and a lazy card loads only once it is shown.
  A hidden tile is not rendered at all; an icon's body is `display: none`, so its card loads only
  when its panel opens; a folded tile's body is `display: none` after the one load that found it
  empty. None of them query in the background.
- **Double-click** on a widget with a page (`DashboardLayout::page()`, sent as `data-page`, only
  pages the role may open) opens that page: not while customising, not on its own controls,
  fields or links, not inside an open panel. On an icon, one click = panel, double-click = page.
  A shortcut with a card: on a computer one click shows the card in the panel, a double-click opens
  the page; on a phone a tap opens the page.
- **Ending Customise**: Done, Escape, or a click on empty space. Both the press and the click must
  land outside every tile, control, dialog, sheet backdrop and the phone-bar picker, so ending a drag
  or a resize never counts; not while Add or a confirmation is open.
- **Red on the home screen**, only for its three jobs: the primary action (Done), what is current
  (today in the calendar; the clock's second hand, hub and working-day bar mark *now*), and what
  waits on the viewer or is late (shortcut counters, the late strip, a decision waiting over a day).
  Pins, grips, size labels, guide cells, icons and folded lines are ink and neutrals, never red,
  and red is never tinted pale.
- **Customising**: "Customise" or a long press; tiles wiggle once and faint cells (`soft` fill,
  dashed `line`) appear. Drag to move, or focus a tile and use the arrow keys (they follow the
  reading direction; Home / End). The dark grip sits in the inline-end corner: dragging towards
  the inline end or down grows the tile, cell by cell, with a "2×3" label; arrow keys on the grip
  do the same. × hides, the pin keeps a tile first (pressed = ink fill, not red), Add brings a
  tile back, Reset (with a confirmation) returns to the role's default. Every control is 44px to
  the finger and labelled. `prefers-reduced-motion` turns off the wiggle and the glides.
  `?customise=1` opens the page in this mode (the command palette's "Customise dashboard").
- **Loading**: each widget is its own Livewire component, mounted lazily inside its tile with
  the card placeholder (see Lazy loading above). A card
  shows its own load failure (`<x-ui.load-error>`) inside the tile; a tile whose lazy load fails
  outright says so in place, and the rest of the page carries on. Saving is background and
  renderless, one request at a time; Add (for a tile the page never drew) and Reset render.

**Adding a tile:**

1. `DashboardLayout::WIDGETS`: `key => [icon, min [c, r], max [c, r], default [c, r] on the
   8-column grid, shown by default]`; its name in `resources/lang/{en,ar}/dashboard.php`
   (`tiles.<key>`); its permission and scope rule in `DashboardLayout::allowed()` if it is not
   for everyone.
2. `DashboardLayout::COMPONENTS`: `key => YourCard::class` (the one registration point; a key
   without a line is kept in layouts but never drawn nor offered).
3. The component: `use IsDashboardTile` (its placeholder) and `HandlesLoadFailure` with
   `<x-ui.load-error>` for a failed load. Its root fills the tile (`h-full`) and draws the card:
   card fill, 1px `line-soft` border, 12px radius, `shadow-sm`. Make it size-aware with the
   `tile` container.
4. It arrives in a Livewire update, not with the page: an inline `<script>` does not run and
   `@push` never reaches the layout. Use `@script` (it runs just before the card replaces its
   placeholder, so wait a frame for the markup) or `@assets`.
5. List its public methods (`placeholder`, `retryLoad`, …) in `config/access-control.php`
   `livewire_exempt`.

A card may read the home screen's Alpine state: `editing`, and `styleOf('<key>')` for its look.

**The calendar tile** (`Dashboard\CalendarCard`, data from `App\Services\Dashboard\CalendarFeed`,
styles `.hc-cal-*` / `.hc-mark*` in `dashboard.css`):

- **Markers are shapes, all in ink** (`ink-3` on days outside the month), never a hue: my leave =
  solid dot, team leave = ring (1.5px), holiday = diamond, birthday = triangle, contract end =
  square, news = short bar. At most four under a day. A new event type needs a new *shape*, its
  name in the legend and in the day dialog.
- **Today** is the only red: a solid brand-red day number (`aria-current="date"`).
- **Size**: the month grid at every size (min 3×3); from 6×4 up, "Next 7 days" and the legend
  beside it.
- **Navigation**: previous / next month chevrons mirror under `[dir=rtl]`; "Today" appears only
  while another month is shown.
- **Day dialog**: a real modal (`role="dialog"`, `aria-modal`, named by its heading, `x-trap`
  holds and returns focus, Escape closes), listing that day's events with links, and "Request
  leave for this day" (the leave form, `leaves.index?new=1`, with the date filled in) only for people
  who may request leave, and only for today or a later day.
- **Scope**: each type is read once per month behind its page's own permission and scope (see the
  class docblock); a projection never carries a reason, a balance, pay or a year of birth.

## Out of reach on purpose

The contract PDF (`resources/views/pdf`, `livewire/contract/details/**`) and the mail templates do
not load `app.css` and keep their own styling. They are not part of this theme.
