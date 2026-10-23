# M1 (shell + leave slice): phone usability

Task M1 in [ux-prompts-v3.md](ux-prompts-v3.md), slice: header, shared modal, leave request flow, notifications UI.
Employee create/resume/list/details and contract views belong to another slice.
Status: **implemented and committed (19cbdb51); QA + technical-lead review still pending.**
Prerequisite B2 has **no delivered notes file** in `docs/ux/` (only b1, b3, b4, b5-d, b6 exist), so I read the source it left (A5-M guarded modals, `GuardsModalClose`, `WithFeedback`) and kept their contract unchanged.

## What changed

| Area | Change |
|---|---|
| `resources/css/app.css` | Two custom variants. `sheet:` = viewport narrower than 640px **or** a short landscape phone (height <= 500px and width <= 950px). `dialog:` = the exact complement (the unchanged desktop/tablet layout). Plus one plain rule that drops the body's bottom padding under a pinned action bar. |
| `components/modal.blade.php` | Below the breakpoint the panel is a full-screen sheet: no gutter, `min-h-dvh` (not `vh`), square corners, `scroll-pb-28` on the scroll root so a focused field is never left under the bar. Desktop classes moved from `sm:` to `dialog:` and are otherwise identical. Escape/guard/focus-trap logic untouched. |
| `components/modal/dialog.blade.php` | Phone: title bar is sticky (safe-area top inset), body fills the sheet. |
| `components/modal/actions.blade.php` (new) | Save/Cancel row. Desktop: ordinary right-aligned row. Sheet: sticky to the bottom, full-width buttons, `env(safe-area-inset-bottom)`. |
| Leave dialogs (`employee/leave/index`, `all`, `balance`) | Use `<x-modal.actions>`. The request form lost its `max-h-[60vh] overflow-y-auto` nested scroller on phones (second scrollbar hid Submit). `all` details modal used `space-x-2`, which is direction-blind in RTL; now `gap-2`. |
| `components/leave-card.blade.php` | The Annual card pushed the page to 619px wide at 375px (the figure + divider + details were one non-wrapping row). On phones the details wrap under the figure and the divider is hidden. |
| `livewire/layout/header.blade.php` | Hamburger, EN/AR links and avatar are 44px targets on phones. The guide, main-website and feedback icons are hidden below `sm` and appear as 44px items in the account menu (the top bar could not hold nine controls: at 320px the hamburger was off-screen at x=-43). Account menu gets `max-h` + scroll. |
| `components/layouts/app/notification.blade.php` | Bell is 44px on phones. The panel was anchored to the bell, so it overflowed the viewport by 1px (EN) / 1px (AR) at 375px and by 25px at 320px; on phones it is now `fixed` between 12px gutters. List height uses `dvh`. From `sm` up it is anchored as before. |
| `components/tour/button.blade.php` | 44px on phones (size-9 from `sm`). |

No policy, authorization, schema or server-action change. Cancel, Escape, header close, the close guard and `confirmSave`/`submitDecision` are the same calls as before.

A Tailwind trap worth remembering: a **comma list inside one `@custom-variant`** was compiled to its first clause only, so landscape phones silently lost every `sheet:` utility. The variants are therefore blocks with one `@slot` per media query, and `PhoneShellTest` pins that exact text.

## Acceptance evidence

Method: headless Chromium (Playwright, `is_mobile`, touch) against a **synthetic SQLite copy** (X1/X2 harness fixture, never the real database), personas `staff.alp` (employee) and `hr.alp` (HR). Page overflow = `document.documentElement.scrollWidth` vs `clientWidth`, plus a scan for elements outside the viewport that are not inside a scroll container.
The built-in browser pane was shared with the other agent (its commands navigated my tab), so it was not used for the final numbers.

| AC | Check | Result |
|---|---|---|
| AC-M1-01 | `/leaves` (employee), `/notifications`, `/`; `/leaves/all` and `/leaves` (HR); 375x812 and 812x375, EN and AR | `scrollWidth == clientWidth` on every page, no clipped element. Before: `/leaves` EN 375 was 619px wide. |
| AC-M1-01 | Header at 375x812 EN/AR, 320x568 EN, 812x375 EN/AR | All controls inside the viewport and 44x44 on phones; at 320 the hamburger is at x=8. |
| AC-M1-01 | Bell and account panels, same sizes | Bell panel 12..363 of 375 (EN and AR), 12..308 of 320; account menu inside the viewport everywhere. |
| AC-M1-01 | Request-leave sheet (annual + hour, the longest variant), EN/AR, both orientations | Sheet fills the viewport; no page overflow. |
| AC-M1-02 | Focus Reason, bar position | 375x812: Reason 682-721, bar 749-812, not covered. 812x375: Reason 244-284, bar 312-375 (flush to the bottom), not covered. Same in AR. |
| AC-M1-02 | HR create-leave and decision sheets (375x812, 812x375, EN/AR) | Bar flush at the bottom (749-812 portrait, 312-375 landscape), no overflow. |
| AC-M1-02 | Not tested | A real virtual keyboard (headless has none), 200% text zoom, a real screen reader. |
| AC-M1-03 | Desktop 1440x900 request dialog | Centred 1152px panel (144..1296), same layout as before; the bar is an ordinary row. |
| AC-M1-03 | Authorization | Untouched. `ModalCloseGuardTest`, `AccessibilityTest`, `LeaveRequestSubmitFlowTest`, `LeaveChecklistNotificationStatesTest`, `A4BilingualTest`, `LeaveSelectorStateTest` still pass (88 tests). |

Tests added: `tests/Feature/PhoneShellTest.php` (7 tests). They are render and contract tests, not behavior tests: they pin which header controls exist at phone size and where the secondary links went, 44px targets, the notification panel's phone anchoring, the sheet and dialog class contract of the modal, the complementary variants, the pinned action bar in all three leave views and its absence of a direction-blind `space-x`. The behavior that matters (guarded close, Cancel never submits, duplicate-safe save, authorization) is already covered by the suites above and was not changed.

Full suite on the shared working tree: 867 passed, 22 failed. The failures (`EmployeeTableB4Test`, `ListStatesTest`, `ProductTourTest`, `TaskTelemetryTest`) were `ViewException`s while the other slice's employee views were mid-edit; they pass when re-run (24 + 24 tests green) and none touch files in this slice.

## Limits

- No real device, virtual keyboard, 200% zoom or screen reader. Keyboard avoidance rests on `scroll-pb-28` + `dvh`; on iOS (visual viewport resize) it is unverified.
- `viewport-fit=cover` is not set, so the safe-area insets resolve to 0 today; they are in place for when D2 adds it.
- Every other modal in the app (employees, contracts, basics...) now also becomes a full-screen sheet on phones through the shared component, but only the leave dialogs have the pinned bar. Dialogs elsewhere get the sticky title and full height only; the other slice can adopt `<x-modal.actions>`.
- A very short phone-height sheet whose form has only one or two fields shows the bar at the bottom with blank space above (intended).
- A decorative `.csb` custom-scrollbar element sits outside the viewport on some modal pages (x = -14 / 381); it is clipped, adds no page scroll, and pre-dates this work.
- The leave tables themselves still scroll horizontally *inside* their container on phones (table, not page). Replacing them with cards is the other slice's pattern (employee list) and was not done here.
- Header at 812x375 keeps its 70px height, about a fifth of the screen; not changed.
- Unchanged and out of scope: the sidebar (it already slides off-canvas and did not block phones), `form.blade.php` (an unused legacy leave form).
