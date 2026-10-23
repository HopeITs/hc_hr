<?php

namespace App\Livewire\Concerns;

use App\Models\SavedTableView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * B4-D personal saved views (docs/ux/table-contract.md section 5.1).
 *
 * Use together with RemembersTableState (it applies a view through that trait's
 * sanitizeTableState(), so a filter or column the active role may not use is
 * dropped with the usual notice). Every query is scoped by the authenticated
 * user, this table and the ACTIVE role context, so a view of another user or
 * of another role is a 404, and is never listed.
 *
 * Only table state is stored: never row ids, results or pay figures.
 */
trait HasSavedViews
{
    /** Bound to the "save current view" input. */
    public string $savedViewName = '';

    /** @return Collection<int, SavedTableView> the caller's views for this table and active role */
    public function savedViews(): Collection
    {
        // Before the migration has run the panel is simply empty; the list itself must keep working.
        if (! once(fn () => Schema::hasTable('saved_table_views'))) {
            return new Collection;
        }

        return $this->ownSavedViews()->orderBy('name')->get(['id', 'name']);
    }

    public function saveView(string $name): bool
    {
        $name = $this->validatedViewName($name);

        if ($name === null) {
            return false;
        }

        if ($this->ownSavedViews()->count() >= SavedTableView::LIMIT) {
            $this->addError('savedViewName', __('general.views-limit', ['max' => SavedTableView::LIMIT]));

            return false;
        }

        SavedTableView::create([
            'user_id' => $this->savedViewUserId(),
            'table' => $this->savedViewTable(),
            'role_context' => $this->savedViewRoleContext(),
            'name' => $name,
            'state' => $this->capturedViewState(),
        ]);

        $this->savedViewName = '';
        $this->resetErrorBag('savedViewName');

        return true;
    }

    public function applyView(int $id): void
    {
        $state = $this->ownSavedViews()->findOrFail($id)->state;
        $state = $this->cleanedViewState(is_array($state) ? $state : []);

        $this->globalSearch = $state['globalSearch'];
        $this->search = $state['search'];
        $this->filters = $state['filters'];
        $this->sortColumn = $state['sortColumn'];
        $this->sortDirection = $state['sortDirection'];
        $this->perPage = $state['perPage'];

        if ($state['columns'] !== []) {
            $this->columns = $state['columns'];
            $this->columnOrder = $state['columnOrder'];
        }

        $this->stateReset = false;
        $this->resetPage();
        // Same safe path as a restored session: drops what this role may not use.
        $this->sanitizeTableState();
    }

    public function renameView(int $id, string $name): bool
    {
        $view = $this->ownSavedViews()->findOrFail($id);
        $name = $this->validatedViewName($name, $view->getKey());

        if ($name === null) {
            return false;
        }

        $view->update(['name' => $name]);
        $this->resetErrorBag('savedViewName');

        return true;
    }

    public function deleteView(int $id): void
    {
        $this->ownSavedViews()->findOrFail($id)->delete();
        $this->resetErrorBag('savedViewName');
    }

    /** Table state only, in the package's own property names. */
    protected function capturedViewState(): array
    {
        return $this->cleanedViewState([
            'globalSearch' => $this->globalSearch,
            'search' => $this->search,
            'filters' => $this->filters,
            'sortColumn' => $this->sortColumn,
            'sortDirection' => $this->sortDirection,
            'perPage' => $this->perPage,
            'columns' => $this->columns,
            'columnOrder' => $this->columnOrder,
        ]);
    }

    /** Whitelist and type-coerce, so nothing but table state is ever stored or applied. */
    protected function cleanedViewState(array $state): array
    {
        $entries = fn ($value): array => collect((array) $value)
            ->filter(fn ($v) => is_scalar($v) || (is_array($v) && collect($v)->every(fn ($i) => is_scalar($i))))
            ->all();
        $codes = fn ($value): array => array_values(array_map('strval', array_filter((array) $value, 'is_scalar')));
        $perPage = (int) ($state['perPage'] ?? 0);
        [$defaultColumn, $defaultDirection] = $this->defaultSort();

        return [
            'globalSearch' => is_string($state['globalSearch'] ?? null) ? $state['globalSearch'] : '',
            'search' => $entries($state['search'] ?? []),
            'filters' => $entries($state['filters'] ?? []),
            'sortColumn' => is_string($state['sortColumn'] ?? null) ? $state['sortColumn'] : $defaultColumn,
            'sortDirection' => in_array($state['sortDirection'] ?? null, ['', 'asc', 'desc'], true) ? $state['sortDirection'] : $defaultDirection,
            'perPage' => in_array($perPage, $this->perPageOptions(), true) ? $perPage : $this->perPage,
            'columns' => $codes($state['columns'] ?? []),
            'columnOrder' => $codes($state['columnOrder'] ?? []),
        ];
    }

    /** The only door to the table: owner + this table + the active role context. */
    protected function ownSavedViews(): Builder
    {
        return SavedTableView::query()
            ->where('user_id', $this->savedViewUserId())
            ->where('table', $this->savedViewTable())
            ->where('role_context', $this->savedViewRoleContext());
    }

    protected function savedViewUserId(): int
    {
        $id = auth()->id();
        abort_if($id === null, 403);

        return (int) $id;
    }

    protected function savedViewTable(): string
    {
        return $this->identifier();
    }

    protected function savedViewRoleContext(): string
    {
        return Str::slug(auth()->user()?->myRole() ?? 'none');
    }

    /** Trimmed name, or null after adding a validation error (empty, too long, duplicate). */
    protected function validatedViewName(string $name, ?int $ignoreId = null): ?string
    {
        $name = trim($name);

        $validator = Validator::make(
            ['name' => $name],
            ['name' => ['required', 'string', 'max:60']],
            attributes: ['name' => __('general.views-name')],
        );
        $validator->after(function ($v) use ($name, $ignoreId) {
            $taken = $this->ownSavedViews()->where('name', $name)
                ->when($ignoreId, fn (Builder $q) => $q->where('id', '!=', $ignoreId))->exists();

            if ($taken) {
                $v->errors()->add('name', __('general.views-name-taken'));
            }
        });

        if ($validator->fails()) {
            $this->addError('savedViewName', $validator->errors()->first('name'));

            return null;
        }

        return $name;
    }
}
