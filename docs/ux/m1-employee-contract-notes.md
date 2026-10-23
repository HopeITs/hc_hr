# M1 - Employee and contract journeys on phones (slice notes)

Slice: employee create/resume/edit, employee list/details/drawer, contract list/review/composer. Leave request, notifications, header and modal were owned by another agent and are untouched.

## What was already fine (measured first)
At 375x812, EN and AR, these had no page-level horizontal scroll before any change: employee list (card view from B4), profile (accordion), employee form tabs (all 11), contract list, contract create/edit composer (accordion + fixed save bar).

## Defects found and fixed
| Surface | Defect | Fix |
|---|---|---|
| Employee create/edit | Eleven full-width section buttons (most disabled in create mode) pushed the form ~1.5 screens down | Below `lg`: one labelled "Section" selector with an `n / N` counter; locked sections stay listed but disabled, plus a one-line hint. Desktop list unchanged (>= lg). One `$sections` array feeds both, so they cannot disagree. |
| Employee form save rows | Save / Next / Cancel at the very end of a long form | `x-forms.dirty-gate` rows (all employee tabs) become a sticky bottom action bar below `lg` (`.m1-action-bar`): safe-area padding, 44px targets, `scroll-padding-bottom` so a focused field is scrolled clear of it |
| Contract review (`/contracts/{id}`) | Page scrolled sideways (A4 sheet header buttons, pager fixed at 210mm; scrollWidth 810 at 375) | Header actions wrap full width; pager `w-full max-w-[210mm]`, safe area, stacked on phones; below `lg` the on-screen sheet reflows (auto height, EN/AR blocks stacked, signatures static). Scoped to `.contract-review` + `@media screen`, so the PDF export and print keep the A4 layout (`_styles` not touched). |
| Contract composer save bar | Fixed bar ignored the home-indicator area; progress label truncated to "0"; 40px buttons | Safe-area padding, full-width buttons with the progress text on its own line, 44px targets, employee line hidden in short landscape, more bottom padding, `scroll-padding-bottom` |
| Drawers (employee, contract) | Primary action only at the end of scroll | Footer sticky with safe-area padding, full-width links on phones |
| List tables (phone cards) | Three stacked filter selects pushed the first result below the fold | Phone filters collapse behind a "Filters" button (Alpine state, survives Livewire morph); filters still rendered. Shared vendor view, desktop (`md:hidden`) unaffected. |
| Page headers | Title + export/add buttons could overflow | `flex-wrap` + gap on employee and contract modules |

Landscape note: 812px is `md`, so the shell shows the desktop sidebar. The employee form switch moved from `md` to `lg` (tab components `x-tab.form`/`x-tab.list` are used only by the employee form) so the form has the selector instead of a 192px side column next to the 240px sidebar.

## Files changed
resources/views/livewire/employee/{form,index,drawer}.blade.php; employee/tabs/personal-info-tab.blade.php; components/tab/{form,list}.blade.php; components/forms/dirty-gate.blade.php; livewire/contract/{contract-details,contract-drawer,contracts-module}.blade.php; contract/details/_pager.blade.php; contract/composer/{_action-bar,_screen}.blade.php; vendor/livewire-table/livewire/livewire-table.blade.php; resources/css/app.css (three appended M1 blocks); resources/lang/{en,ar}/mobile.php (new, 2 keys); tests/Feature/PhoneEmployeeContractFlowTest.php (new).

## Security / pay
No policy, route, auth or schema change. `setActiveTab` is the same Livewire action the desktop buttons call (no new server surface); the selector lists the Contracts History section only when `$canViewContractHistory`. Pay is untouched: withheld-on-load and `canViewSalary()` paths not modified; existing SalaryVisibility / ContractExportUi / EmployeeEditContractsHistory tests pass.

## Acceptance evidence (actual)
Environment: built-in browser, synthetic SQLite copy (X2 harness, port 8398, HR-ALP), `npm run build`.
- AC-M1-01: `document.documentElement.scrollWidth <= innerWidth` at 375x812 in EN and AR for employees list, create, edit (all 11 tabs, EN), profile, contracts list, contract review, contract edit, contract create; at 812x375 EN for the same pages. Before: contract review scrollWidth 810 at 375.
- AC-M1-02: contract create, keyboard Tab moved the focused field to y=426 with the save bar top at 717 (not covered). Sticky bars confirmed at viewport bottom (bar.bottom == innerHeight) in EN and AR.
- AC-M1-03: at 1280x720 the desktop tab list shows and the selector is hidden; no layout change. Tests: `php artisan test --filter="Employee|Contract|Dirty|Tab|ProfileDrawer|Accessibility|A4Bilingual"` 457 passed; new `PhoneEmployeeContractFlowTest` 6 passed; Pint run on new PHP.

## Limits / not done
- Real virtual-keyboard behaviour, 200% text zoom and screen-reader runs were not performed (emulated viewport only). `interactive-widget=resizes-content` in the viewport meta (partials/head.blade.php, shared) would make fixed/sticky bars ride above the on-screen keyboard on Android Chrome; not changed here, recommended to the shell owner.
- Login card was a fixed `w-96` (384px); now `w-full max-w-96`. Viewport meta now carries `interactive-widget=resizes-content` (Android Chrome keyboard avoidance); real-device verification still pending. `min-h-screen` in the shared layout is not `dvh` (shell owner).
- The 768-1023px band now uses the phone selector/action bar for the employee form.
- Contract review is still a paginated A4 document; on phones it reflows rather than being a separate summary view.
