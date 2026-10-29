# D2 · Phone self-service: bottom navigation, safe areas, connection notice

Task: D2 in [ux-prompts-v3.md](ux-prompts-v3.md) · Date: 2026-10-28 · Branch `hr-ux-by-rafi`
Inputs:
- M1 notes: [m1-shell-leave-notes.md](m1-shell-leave-notes.md), [m1-employee-contract-notes.md](m1-employee-contract-notes.md).
- C1 menu, C3 inbox, C4 home, C5 calendar, D1 profile.

Status: **built and tested; review pending. No migration, no data change.**

## 1. What changed on phones

| Part | Behaviour |
|---|---|
| **Bottom navigation** (below 768 px, where the sidebar is a drawer) | <ul><li>Up to five items, each shown only when the role holds the permission its page checks: **لوحة التحكم** (Home) · **اجازاتي** (My leaves, `leaves.view-own`) · **صندوق المهام** (Work inbox, with the same count as the menu) · **تقويم الفريق** (Team calendar, `leaves.view-own`) · **ملفي الشخصي** (My profile, when the user has an employee record).</li><li>Labels are the menu's names (C0 §8).</li><li>The current page is marked with `aria-current` and a bar, not colour alone.</li><li>Touch targets are 56 px.</li></ul> |
| **It never covers a field** (AC-D2-02) | It steps aside: <ul><li>while a typing field has focus, by both CSS `:focus` and `focusin`/`focusout` events;</li><li>on pages with their own sticky save row (M1 `.m1-action-bar`, the contract composer);</li><li>sheets and dialogs (z-50) cover it.</li></ul> The page reserves 64 px plus the safe area below its content, and toasts and the connection notice sit above it. |
| **Safe areas** | The viewport now has `viewport-fit=cover` (M1 had left the insets at 0). The fixed header pads by the top inset, and the bottom bar and M1 save rows by the bottom inset. |
| **Connection** | Without a connection, a notice appears: «لا يوجد اتصال بالإنترنت. ما تكتبه يبقى في الصفحة، لكن لا يُحفظ شيء حتى يعود الاتصال.» **Nothing is cached for offline use:** no service worker, no stored HR data. A failed save still gets G-B-2's toast with *Try again*. |
| Landscape (812×375) | From 768 px the sidebar is shown, so there is no bottom bar and no reserved space. |
| Desktop | Unchanged; the bar is `md:hidden`. |

## 2. Where

| File | Change |
|---|---|
| `app/Livewire/Layout/BottomNav.php`, `livewire/layout/bottom-nav.blade.php` | The bar. It re-renders on `inbox-changed`, like the sidebar. `refreshInbox` is listed in `livewire_exempt`. |
| `components/layouts/app.blade.php` | Mounts the bar and the connection notice. |
| `resources/css/app.css` "D2: bottom navigation" | The step-aside rules, the content padding, and the toast and notice offsets. |
| `partials/head.blade.php`, `livewire/layout/header.blade.php` | `viewport-fit=cover`; the header's top inset. |
| `components/toasts.blade.php` | `data-toasts` hook for the offset. |

## 3. Tests (`tests/Feature/BottomNavTest.php`, 5 tests)

| AC | Test |
|---|---|
| **AC-D2-01** Works by role permissions | <ul><li>`test_each_role_gets_the_destinations_its_permissions_open`: employee 4 items; manager and HR 5; a role with no leave permission and no record gets Home only.</li><li>`test_every_link_opens_for_the_role_that_sees_it_in_both_languages`: every link opens (no 403/404/500) for employee, manager and HR, in EN and AR.</li><li>`test_the_inbox_count_is_the_menus_count_and_the_current_page_is_marked`.</li></ul> |
| **AC-D2-02** Bars never obscure fields | `test_the_bar_steps_aside_for_focused_fields_and_save_rows` checks the stylesheet's rules. The real proof is the browser checks (§4). |
| Shell, no offline caching | `test_the_shell_carries_the_bar_the_safe_areas_and_the_connection_notice` |
| **AC-D2-03** Desktop unchanged | The full suite passes: **1026 tests, 5802 assertions, 0 failures**. |

## 4. Browser checks (synthetic copy, Arabic, `manager.alp`)

| Size | Check | Result |
|---|---|---|
| 375×812 | Home | Five items «لوحة التحكم [current] · اجازاتي · صندوق المهام · تقويم الفريق · ملفي الشخصي», each 56 px; page bottom padding 64 px; no sideways scroll. |
| 375×812 | New leave request (`?new=1`) | The sheet covers the bar: the bottom of the screen is the sheet's own «إرسال الطلب / إلغاء» row. «السبب» (561–600) sits above that row (750–812). |
| 375×812 | Focus rule (retried with the page window focused) | On the team calendar, focusing the team selector gave `:focus` = true and `data-typing=true`, and the bar **disappeared** (`display: none`). On blur it came back. The first attempt could not show this: the preview had not given its page window focus, so no focus fired. |
| 812×375 | Landscape | No bar (sidebar shown); no reserved space; no sideways scroll. |

## 5. Limits

- **No real device:** the on-screen keyboard, iOS visual-viewport resizing, 200 % text and a screen reader were not tested (the same limit as M1 and G-B-11). This is for X2-R / G-C.
- **Phone cards, agenda views and form sections:** the M1, B4, C5 and D1 phone patterns were reused as they are; no further card or agenda redesign was needed for these four destinations.
- **Installable app** is D2-PWA (DEC-07), not here.
