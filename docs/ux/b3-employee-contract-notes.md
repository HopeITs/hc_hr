# B3 - Employee and contract lists: loading / empty / filtered / error

Scope: `EmployeeTable`, `ContractsTable` (ramonrietdijk LivewireTable) only.

## Mechanism
- `App\Livewire\Concerns\HasListStates` (new) - used by both tables. Adds:
  - `paginate()` override wrapped in `guardedLoad()`: a failing query reports the exception, sets `$loadFailed`, renders an empty page (no 500, no exception text in the UI).
  - `renderEmptyState()` returns `x-ui.load-error` (error, retry = `retryLoad`), `x-center-empty-state variant=filtered` + "Clear filters" (`clearAllFilters` = `clearSearch()` + `clearFilters()`, also persists session for ContractsTable), or `variant=empty` + optional create action.
  - `emptyStateCreate()` per table: Add employee needs `employees.create`, Add contract needs `contracts.create` (same permission as the route middleware); otherwise no button.
  - `stateAnnouncement()` - result count / state text.
- Package hook: the app-published view `resources/views/vendor/livewire-table/table/table.blade.php` calls `renderEmptyState()` only when the method exists; the other ~20 tables keep the plain "No results" row. `livewire/livewire-table.blade.php` adds an `sr-only role=status aria-live=polite` region (count/state, or "Loading" while a request is in flight) and `wire:loading.class="opacity-60"` on the table frame for search/filter/paging/clear/retry (rows stay in place: no layout jump).
- Lang: `state-results-count`, `state-add-employee`, `state-add-contract` (EN + AR, AR plural forms).

## Security
- `query()` untouched (city scope / `visibleTo`); search, clear and retry go through it. Neither table has a salary column; tests assert a distinctive salary is neither rendered nor searchable by role without `canViewSalary`.

## Tests
`tests/Feature/Livewire/ListStatesTest.php` (8): empty vs filtered, create action per permission (employee and contract), clear filters restores rows (global and column search), city scoping, no pay leak, load failure + retry, announcement. With related suites (Livewire dir, ContractsSearchPersistence, ContractAccessByRole, ContractExportUi, ProductTour, TaskTelemetry, ContractRenewal, ContractAutoRenewalWorkflow): 152 passed. `pint --dirty` run.

## Limits
- Skeleton rows are not used; loading is dimming + live-region text (no `aria-busy`: Livewire's `wire:loading.attr` sets an empty value, not "true").
- The vendor-published view change is shared; other tables ignore it unless they adopt the trait.
- Browser/RTL/200% zoom/screen-reader checks (SHARED RULES) not performed.
- `wire:loading` targets assume default Livewire method names (`gotoPage`, `nextPage`, `setPage`).
