---
name: HC-HR
description: Bilingual HR management system for Christian Hope Center, a multi-city Syrian NGO.
colors:
  hope-red: "#c2151c"
  hope-red-deep: "#a51218"
  canvas-grey: "#f4f5f7"
  paper-white: "#ffffff"
  chrome-mist: "#f9fafb"
  sunken-well: "#eeeff0"
  selection-grey: "#e6e7e8"
  ink-strong: "#2b2a2c"
  ink-body: "#414042"
  ink-muted: "#6d6e71"
  ink-subtle: "#939598"
  line-hairline: "#e6e7e8"
  line-control: "#bcbec0"
  button-quiet: "#dcddde"
  button-quiet-hover: "#bcbec0"
typography:
  h1:
    fontFamily: "SomarSans, Lato, ui-sans-serif, system-ui, sans-serif"
    fontSize: "2.125rem"
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: "-0.02em"
  h2:
    fontFamily: "SomarSans, Lato, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.6875rem"
    fontWeight: 600
    lineHeight: 1.15
    letterSpacing: "-0.015em"
  h3:
    fontFamily: "SomarSans, Lato, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.375rem"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "-0.01em"
  h4:
    fontFamily: "SomarSans, Lato, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "-0.005em"
  body:
    fontFamily: "SomarSans, Lato, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.55
  small:
    fontFamily: "SomarSans, Lato, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 400
    lineHeight: 1.5
  caption:
    fontFamily: "SomarSans, Lato, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 500
    lineHeight: 1.45
    letterSpacing: "0.01em"
rounded:
  inset: "4px"
  control: "8px"
  surface: "12px"
  round: "9999px"
spacing:
  space-1: "4px"
  space-2: "8px"
  space-3: "12px"
  space-4: "16px"
  space-5: "24px"
  space-6: "32px"
  space-7: "48px"
  space-8: "64px"
  space-9: "96px"
components:
  button-primary:
    backgroundColor: "{colors.hope-red}"
    textColor: "{colors.paper-white}"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    padding: "8px 16px"
  button-primary-hover:
    backgroundColor: "{colors.hope-red-deep}"
    textColor: "{colors.paper-white}"
  button-secondary:
    backgroundColor: "{colors.button-quiet}"
    textColor: "{colors.ink-body}"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    padding: "8px 16px"
  button-secondary-hover:
    backgroundColor: "{colors.button-quiet-hover}"
  button-outline:
    backgroundColor: "transparent"
    textColor: "{colors.hope-red}"
    rounded: "{rounded.control}"
    padding: "8px 16px"
  button-outline-hover:
    backgroundColor: "{colors.hope-red}"
    textColor: "{colors.paper-white}"
  input:
    backgroundColor: "{colors.paper-white}"
    textColor: "{colors.ink-strong}"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    padding: "8px 12px"
  card:
    backgroundColor: "{colors.paper-white}"
    rounded: "{rounded.surface}"
    padding: "24px"
  pill:
    backgroundColor: "{colors.sunken-well}"
    textColor: "{colors.ink-muted}"
    typography: "{typography.small}"
    rounded: "{rounded.control}"
    padding: "2px 8px"
---

# Design System: HC-HR

## Overview

**Creative North Star: "The Steady Hand"**

HC-HR is where a humanitarian organisation keeps its promises to its own staff: contracts, pay, leave, and hiring across six Syrian cities. The interface should feel like a capable colleague who never raises their voice. Its care shows in restraint: a calm grey canvas, white paper surfaces, a single warm red that appears only where someone has to act, and type that sits comfortably in both English and Arabic.

Density is that of a working tool. The base is 14px, tables and forms dominate, and the layout is meant for someone processing forty records before lunch. Depth is almost flat. Surfaces are separated by tone and hairlines, with shadows only as a quiet whisper. Every screen mirrors cleanly for RTL; direction is a first-class layout input, not an afterthought.

Nothing is decorative for its own sake. Brand is carried by the precision of small things: the red asterisk on a required field, the firm 0.98 press of a primary button, the exact grey of a selected row.

**Key Characteristics:**
- One accent, strictly rationed; everything else is a warm-leaning neutral ramp.
- 14px base, major-third type scale, SomarSans for both scripts.
- Three radii chosen by element role, never by taste.
- Flat-by-default, with shadows tinted from the brand's own dark ink.
- Logical properties throughout (`ms-`, `pe-`, `start`/`end`) so EN/AR mirror for free.

## Colors

A near-monochrome grey world with one warm red that means "act here".

### Primary
- **Hope Red** (`hope-red`): primary CTAs, the active navigation item, focus rings, destructive confirmations and the required-field marker. Nothing else.
- **Hope Red Deep** (`hope-red-deep`): hover and pressed state of Hope Red. It is one step darker, never a second accent.

### Neutral
- **Canvas Grey** (`canvas-grey`): the page background, about 60% of any screen.
- **Paper White** (`paper-white`): cards, tables, forms and modals, the surfaces where work happens.
- **Chrome Mist** (`chrome-mist`): sidebar and toolbars, just off-white so chrome recedes behind content.
- **Sunken Well** (`sunken-well`): table heads, wells, neutral pills.
- **Selection Grey** (`selection-grey`): selected and hovered rows and items.
- **Ink Strong / Body / Muted / Subtle** (`ink-strong`, `ink-body`, `ink-muted`, `ink-subtle`): headings, body and table text, labels, placeholders. Ink is outside the surface budget.
- **Hairline** (`line-hairline`) and **Control Line** (`line-control`): dividers and card edges versus input borders.

### Named Rules
**The Rationed Red Rule.** Hope Red covers at most 10% of any screen and appears only on: a primary CTA, the active nav item, a focus ring, a destructive confirm, or a required-field star. Never as a page background or decorative fill.

**The No-Pink Rule.** Never tint Hope Red down with opacity for a selected or soft state. A translucent `#c2151c` reads as pink, which is banned. Use Selection Grey instead.

**The Status Hue Rule.** Status colours (success, warning, info) are not yet brand-owned. Where they exist, such as recruitment pills, each hue carries exactly one meaning across the domain and comes from the enum's `classes()`. Don't invent new ones ad hoc.

## Typography

**Display Font:** SomarSans (with Lato, then system sans)
**Body Font:** SomarSans (with Lato)

**Character:** One family for both Latin and Arabic, so a bilingual page never looks like two products stitched together. The weights run from light to bold; extrabold and black are reserved and rarely justified.

### Hierarchy
- **H1** (700, 34px, 1.1, −0.02em): page titles, rare.
- **H2** (600, 27px, 1.15): section titles on dense pages.
- **H3** (600, 22px, 1.2): card groups and modal titles.
- **H4** (600, 18px, 1.3): card titles and panel headers.
- **Body** (400, 14px, 1.55): the default for all UI and table text. It is set on `body`, so unsized text never falls back to 16px.
- **Small** (400, 12px, 1.5): helper text and secondary metadata.
- **Caption** (500, 11px, 1.45, +0.01em): group labels, badges and counters.

### Named Rules
**The Fourteen Rule.** 14px is the product's base, not 16px. Any size outside the seven steps above is a defect.

## Layout

An 8px rhythm with a 4px half-step (`space-1` to `space-9`): 4px for icon/label gaps, 8px inside controls, 12px for control padding and table cells, 16px between related items, 24px for card padding, 32px between form groups, 48px between page sections. The app shell is a collapsible sidebar on Chrome Mist over a Canvas Grey content area. The sidebar resizes with the `--ease-fluid` curve (`cubic-bezier(0.32, 0.72, 0, 1)`) so panel and content move locked together. Screens reflow to a single column on phones. Tables scroll inside their own container, never the page.

**The Logical Direction Rule.** Use only start/end logical utilities (`ms-`, `pe-`, `text-start`, `ltr:`/`rtl:` variants). A hard-coded left or right is an Arabic bug waiting to happen.

## Elevation & Depth

Flat by default. Surfaces separate through tone (Canvas → Paper) and a hairline border, and shadows only confirm what tone already says. All shadows are tinted with Ink Body (`#414042`), not black, with the light source directly above.

### Shadow Vocabulary
- **e1 resting** (`box-shadow: 0 1px 2px 0 rgb(65 64 66 / 0.06)`): cards, table containers, buttons, inputs.
- **e2 raised** (`box-shadow: 0 2px 8px -2px rgb(65 64 66 / 0.1)`): dropdowns, popovers, sticky bars.
- **e3 overlay** (`box-shadow: 0 8px 24px -8px rgb(65 64 66 / 0.16)`): modals, drawers, dialogs.

### Named Rules
**The Three Heights Rule.** There are exactly three elevations. If something seems to need a fourth, it needs a different surface tone instead.

## Shapes

Gently rounded and chosen by role. **Inset** (4px) is for things inside other things: badges and checkboxes. **Control** (8px) is for anything you press or type into, including pills. **Surface** (12px) is for containers that hold controls: cards, tables, modals. **Round** is for avatars and circular icon buttons only. Borders are always 1px. The 2px focus ring, offset 2px, is the single deliberate exception.

## Components

Refined and restrained: hairline edges, barely-there shadows, a firm press.

### Buttons
- **Shape:** control corners (8px).
- **Primary:** Hope Red with white semibold 14px text, 8px × 16px padding, e1 shadow. Hover deepens to Hope Red Deep. Active scales to 0.98 and drops the shadow. Focus-visible shows a 2px Hope Red outline, offset 2px. It shows an inline spinner while Livewire is loading, and disabled is 60% opacity.
- **Secondary:** a quiet grey fill (`#dcddde`, hover `#bcbec0`) with Ink Body text. Use it for cancel and any non-committal action.
- **Outline:** a Hope Red border and text that fills solid on hover. Use it sparingly, because it still spends red.

### Chips / Pills
- **Style:** control radius, 12px semibold, 2px × 8px padding. Neutral by default (Sunken Well and a muted ink). A status tone comes from the domain enum, one hue per meaning.

### Cards / Containers
- **Corner Style:** surface (12px).
- **Background:** Paper White on Canvas Grey.
- **Shadow Strategy:** e1 resting.
- **Border:** 1px `neutral-100`.
- **Internal Padding:** 24px. Titles are H4 semibold, and body copy is in muted ink.

### Inputs / Fields
- **Style:** white fill, a 1px Control Line border, control radius, 8px × 12px padding, e1 shadow, 14px Ink Strong text with Subtle placeholder.
- **Focus:** a 2px soft grey ring, with the border shifting to Hope Red.
- **Error:** `aria-invalid` plus `aria-describedby` pointing at the message. Required fields get a red decorative star and a screen-reader-only "required" word.

### Navigation
- A collapsible sidebar on Chrome Mist. The active item is marked with Hope Red, and hover and selection use Selection Grey. Locale switching changes the URL prefix (`/en`, `/ar`) and mirrors the whole shell.

## Do's and Don'ts

### Do:
- **Do** use the role radii (`rounded-inset`, `rounded-control`, `rounded-surface`) instead of Tailwind's size radii.
- **Do** use the shared `x-primary-button`, `x-secondary-button` and `x-recruitment.pill` components rather than restyling ad hoc.
- **Do** use the seven text tokens (`text-h1` … `text-caption`) and the e1–e3 shadows only.
- **Do** mark required fields with the `required` prop on `x-forms.input-label`.
- **Do** check every screen in `/ar` before calling it done.

### Don't:
- **Don't** use Hope Red outside its five permitted roles, or at reduced opacity.
- **Don't** use pure-black shadows or introduce a fourth elevation.
- **Don't** hard-code `left`/`right`, `ml-`/`mr-`, or `text-left`.
- **Don't** add new status hues without deciding their single meaning first.
- **Don't** fall back to 16px body text or use extrabold/black weights for emphasis.
