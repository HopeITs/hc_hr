# B4 · Compact tables, preserved context and presets (implementation notes)

Status: **implemented, uncommitted on branch `b4`; formal review pending; not marked done.**
Design source: [table-contract.md](table-contract.md) (B4-D). Tables covered: Employees, Contracts.

## What changed
- `app/Livewire/Concerns/RemembersTableState.php`: session state keyed by user + active role + city scope; URL wins; page clamp; invalid filters dropped with a notice; Clear all (keeps columns/per-page); filter chips; phone card hook `cardView()`.
- Vendor table view: chips, Clear all, notice, cards below `md`.
- `EmployeeTable` + `EmployeeDrawer`: compact columns, `?view=active|inactive` (Inactive HR/GM only), row click opens a read-only drawer, named row actions and More menu, phone cards, export hint.
- `ContractsTable` + `ContractDrawer`: compact columns, `?view=` Active / Ending soon (30 d) / Terminated / Draft reservations (non-Active HR/GM only), drawer without any pay figure, phone cards, export hint.
- Excel export of contracts now follows the picker's columns; `ContractExport::styles()` return type fixed (it was a fatal error).
- `config/access-control.php`: drawer and table actions classified.
- Personal saved views (B4D-Q5, table-contract section 5.1): `HasSavedViews` concern (used with `RemembersTableState` on Employees and Contracts), model `SavedTableView`, migration `2026_10_22_000003_create_saved_table_views_table`. Actions `saveView` / `applyView` / `renameView` / `deleteView` are owner-only (404 for another user's or another role's view), max 10 per user per table, table state only. Applying goes through `sanitizeTableState()` (disallowed filters/columns dropped with the usual notice). "My views" panel in the shared table view (EN+AR). Tests: `SavedViewsTest`.
- **The migration has NOT been run.** Run it yourself: `php artisan migrate`. Rollback: `php artisan migrate:rollback --step=1`. Until it runs the lists keep working and the "My views" panel shows empty (saving fails).
- Tests: `RemembersTableStateTest`, `EmployeeTableB4Test`, `ContractsTableB4Test`; four existing tests adjusted (page clamp, preset, chip echo, drawer instead of navigation).

## Acceptance evidence
| AC | Evidence |
|---|---|
| B4-01 | Drawers are separate components; tests assert search/filters/sort/page unchanged after open/close. |
| B4-02 | Tests: role/city switch restores nothing; out-of-scope filter dropped; forged preset 403; cross-city ids 404. |
| B4-03 | Phone cards tested for rendering; browser check at 375px (EN/AR): no page-level horizontal scroll on Employees and Contracts. Screenshots are kept locally in `docs/ux/evidence/b4/` (git-ignored on purpose). |

Full suite at the time of writing: 867 passed, 0 failed.

## Not done / limits
- Preset is URL-only (not remembered in session) for Contracts; Employees likewise.
- Free-text column searches have no allowed-value check; phone cards offer global search only.
- Leave tables (All leaves, My leaves, Balances) are not converted; outside the B4 inspect scope.
- Excel export of employees has no server-side permission check in the component (pre-existing, unchanged).
- Browser keyboard/focus/RTL checks: see evidence folder.
- No migration, deploy or data change was made.
