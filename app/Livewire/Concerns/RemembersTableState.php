<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Str;
use RamonRietdijk\LivewireTables\Columns\BaseColumn;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Filters\BaseFilter;
use RamonRietdijk\LivewireTables\Filters\SelectFilter;

/**
 * B4-D shared table state: remembered per user + ACTIVE role + city scope,
 * safely reset, with removable filter chips and a phone card layout hook.
 *
 * Use on a `LivewireTable` subclass (works with HasListStates). No other
 * wiring is needed; the table only has to:
 *
 *  - NOT redeclare `$sessionProperties` / `sessionKey()` / `restoreSession()`
 *    (this trait owns them; `$useSession` is ignored);
 *  - optionally override `cardView()` to opt in to cards below `md`;
 *  - optionally override `allowedValuesFor()` for filters whose allowed values
 *    are not a SelectColumn / SelectFilter option list;
 *  - optionally override `clearPreset()` once the table has `?view=` presets.
 *
 * Rules (docs/ux/table-contract.md §4.2):
 *  - URL wins over session; a URL search/filter also stops the saved page being reused;
 *  - the session key contains user id, active role and city scope, so nothing from
 *    another role/city is ever read back (AC-B4-02);
 *  - restored columns are limited to columns the role may see (`canBeSeen`);
 *  - a filter whose value is no longer allowed is dropped and `$stateReset` shows
 *    "A filter was cleared because it no longer applies";
 *  - a page past the last page is clamped to the last page at render time;
 *  - `clearAll()` clears search, filters, sort, preset and page, keeps columns and per-page.
 */
trait RemembersTableState
{
    /** True after a stored/URL filter was dropped because it no longer applies. Display flag only. */
    public bool $stateReset = false;

    public function updatedRemembersTableState(string $property): void
    {
        if (in_array(Str::before($property, '.'), $this->rememberedProperties(), true)) {
            $this->updateSession();
        }
    }

    public function updatedPaginators(): void
    {
        $this->updateSession();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
        $this->updateSession();
    }

    /** Clamp a stale page: past-the-end becomes the last page that exists. */
    public function rendering($view, array $data): void
    {
        $paginator = $view->getData()['paginator'] ?? null;

        if (! $paginator || $paginator->currentPage() <= 1 || $paginator->count() > 0) {
            return;
        }

        if (property_exists($this, 'loadFailed') && $this->loadFailed) {
            return;
        }

        $this->paginators['page'] = max(1, $paginator->lastPage());
        $this->updateSession();
        $view->with('paginator', $this->paginate());
    }

    /** Search, filters, sort, preset and page go back to defaults; column choice and per-page stay. */
    public function clearAll(): void
    {
        $this->globalSearch = '';
        $this->search = [];
        $this->filters = [];
        [$this->sortColumn, $this->sortDirection] = $this->defaultSort();
        $this->clearPreset();
        $this->stateReset = false;
        $this->resetPage();
        $this->updateSession();
        $this->dispatch('list-filters-cleared');
    }

    public function dismissStateNotice(): void
    {
        $this->stateReset = false;
    }

    public function canClearAll(): bool
    {
        return $this->globalSearch !== '' || $this->search !== [] || $this->filters !== []
            || [$this->sortColumn, $this->sortDirection] !== $this->defaultSort();
    }

    // --------------------------------------------------------------- chips

    /**
     * One removable chip per active search/filter.
     *
     * @return array<int, array{group: string, key: string, label: string, value: string}>
     */
    public function activeFilterChips(): array
    {
        $chips = [];

        if ($this->globalSearch !== '') {
            $chips[] = ['group' => 'globalSearch', 'key' => '', 'label' => __('general.search'), 'value' => $this->globalSearch];
        }

        foreach (['search', 'filters'] as $group) {
            foreach ((array) $this->{$group} as $code => $value) {
                $control = $group === 'search'
                    ? $this->resolveColumns()->first(fn (BaseColumn $c) => $c->code() === (string) $code)
                    : $this->resolveFilters()->first(fn (BaseFilter $f) => $f->code() === (string) $code);

                if (! $control) {
                    continue;
                }

                $options = method_exists($control, 'getOptions') ? $control->getOptions() : [];
                $labels = collect((array) $value)
                    ->map(fn ($v) => (string) ($options[$v] ?? $v))
                    ->filter(fn ($v) => $v !== '')
                    ->implode(', ');

                $chips[] = ['group' => $group, 'key' => (string) $code, 'label' => $control->label(), 'value' => $labels];
            }
        }

        return $chips;
    }

    public function removeChip(string $group, string $key = ''): void
    {
        if ($group === 'globalSearch') {
            $this->globalSearch = '';
        } elseif (in_array($group, ['search', 'filters'], true)) {
            unset($this->{$group}[$key]);
        } else {
            return;
        }

        $this->resetPage();
        $this->updateSession();
    }

    // ---------------------------------------------------------------- cards

    /**
     * Blade view for one row on phones (below md) or null to keep the table.
     * The partial receives `$item`, `$link` (row URL or null) and `$table`.
     */
    public function cardView(): ?string
    {
        return null;
    }

    /** Properties written to the session, in the package's own names. */
    protected function rememberedProperties(): array
    {
        return ['globalSearch', 'search', 'filters', 'sortColumn', 'sortDirection', 'paginators', 'perPage', 'columns', 'columnOrder'];
    }

    // ---------------------------------------------------------------- session

    /** Package key + user + active role + city scope. */
    protected function sessionKey(): string
    {
        return once(function (): string {
            $user = auth()->user();
            $cities = $user?->activeCityIds();

            return implode(':', [
                $this->identifier().':state',
                $user?->getKey() ?? 'guest',
                Str::slug($user?->myRole() ?? 'none'),
                $cities === null ? 'all' : (collect($cities)->sort()->implode(',') ?: 'none'),
            ]);
        });
    }

    protected function updateSession(): void
    {
        $data = [];

        foreach ($this->rememberedProperties() as $property) {
            $data[$property] = $this->{$property};
        }

        session()->put($this->sessionKey(), $data);
    }

    protected function restoreSession(): void
    {
        $data = session()->get($this->sessionKey());

        if (! is_array($data)) {
            return;
        }

        $fromUrl = collect($this->rememberedProperties())
            ->filter(fn (string $p) => $p !== 'paginators' && request()->query->has($this->getQueryStringName($p)))
            ->all();
        $narrowedByUrl = array_intersect(['globalSearch', 'search', 'filters'], $fromUrl) !== [];

        foreach ($this->rememberedProperties() as $property) {
            if (in_array($property, $fromUrl, true) || ! array_key_exists($property, $data)) {
                continue;
            }

            $value = $data[$property];

            match ($property) {
                'globalSearch', 'sortColumn', 'sortDirection' => is_string($value) && $this->{$property} = $value,
                'search', 'filters', 'columns', 'columnOrder' => is_array($value) && $this->{$property} = $value,
                'perPage' => is_int($value) && $this->{$property} = $value,
                'paginators' => ! $narrowedByUrl && ! request()->query->has('page') && is_array($value)
                    && $this->paginators['page'] = max(1, (int) ($value['page'] ?? 1)),
            };
        }

        if (request()->query->has('page')) {
            $this->paginators['page'] = max(1, request()->integer('page', 1));
        }
    }

    /** Restore, then make the state safe for what the active role sees now. */
    protected function initialize(): void
    {
        parent::initialize();

        $this->sanitizeTableState();
    }

    // ----------------------------------------------------------- safe reset

    protected function sanitizeTableState(): void
    {
        $columns = $this->resolveColumns();
        $known = $columns->map(fn (BaseColumn $c) => $c->code())->values()->all();

        // Never keep a column the role may not see (resolveColumns already dropped them).
        $this->columns = array_values(array_intersect(array_map('strval', $this->columns), $known));
        $this->columnOrder = array_values(array_intersect($this->columnOrder, $known));
        if ($this->columns === []) {
            $this->initializeColumns();
        }

        $sortable = $columns->filter(fn (BaseColumn $c) => $c->isSortable())->map(fn (BaseColumn $c) => $c->code())->all();
        if ($this->sortColumn !== '' && ! in_array($this->sortColumn, $sortable, true)
            || ! in_array($this->sortDirection, ['', 'asc', 'desc'], true)) {
            [$this->sortColumn, $this->sortDirection] = $this->defaultSort();
        }

        $dropped = false;

        foreach (['search', 'filters'] as $group) {
            $clean = [];
            foreach ((array) $this->{$group} as $code => $value) {
                $allowed = $this->allowedValuesFor($group, (string) $code);

                if ($allowed === false) { // the column / filter no longer exists for this role
                    $dropped = true;

                    continue;
                }

                if ($allowed !== null) {
                    $values = array_map('strval', (array) $value);
                    if (array_diff($values, array_map('strval', $allowed)) !== []) {
                        $dropped = true;

                        continue;
                    }
                }

                $clean[$code] = $value;
            }
            $this->{$group} = $clean;
        }

        if ($dropped) {
            $this->stateReset = true;
            $this->resetPage();
        }

        $this->updateSession();
    }

    /**
     * Allowed values for a column search (`search`) or filter (`filters`) entry.
     *
     * @return array<int, mixed>|false|null list = only these; null = free text / any; false = no such control
     */
    protected function allowedValuesFor(string $group, string $code): array|false|null
    {
        if ($group === 'search') {
            $column = $this->resolveColumns()->first(fn (BaseColumn $c) => $c->code() === $code && $c->isSearchable());

            if (! $column) {
                return false;
            }

            return $column instanceof SelectColumn ? array_keys($column->getOptions()) : null;
        }

        $filter = $this->resolveFilters()->first(fn (BaseFilter $f) => $f->code() === $code);

        if (! $filter) {
            return false;
        }

        return $filter instanceof SelectFilter ? array_keys($filter->getOptions()) : null;
    }

    // ------------------------------------------------------------ clear all

    /** @return array{0: string, 1: string} */
    protected function defaultSort(): array
    {
        $defaults = get_class_vars(static::class);

        return [$defaults['sortColumn'] ?? '', $defaults['sortDirection'] ?? ''];
    }

    /** Hook for `?view=` presets (B4-D §5); nothing to clear until a table has them. */
    protected function clearPreset(): void {}
}
