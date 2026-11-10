<?php

namespace App\Livewire\Employee;

use App\Exports\EmployeeExport;
use App\Livewire\Concerns\HasListStates;
use App\Livewire\Concerns\HasSavedViews;
use App\Livewire\Concerns\RemembersTableState;
use App\Livewire\Concerns\WithFeedback;
use App\Models\City;
use App\Models\Employee;
use App\Models\Position;
use App\Services\TaskTelemetry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\DateColumn;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Enums\SearchScope;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeTable extends LivewireTable
{
    use HasListStates;
    use HasSavedViews;
    use RemembersTableState;
    use WithFeedback;

    /**
     * Named presets. B5 adds "awaiting" (complete volunteers who never had a
     * real contract, a subset of Inactive) and "incomplete" (drafts, which
     * no other view shows). employee-lifecycle.md §5.4.
     */
    public const VIEWS = ['active', 'inactive', 'awaiting', 'incomplete'];

    /** B4 named preset (`?view=`): only ever narrows the authorized query. See effectiveView(). */
    #[Url(as: 'view')]
    public string $preset = 'active';

    protected string $model = Employee::class;

    protected $listeners = ['delete', 'toggleItemStatusConfirmed', 'exportExcel' => 'exportExcel'];

    /** X2-I task B: the first search on the employee list starts a lookup attempt. */
    public function updatedGlobalSearch(): void
    {
        parent::updatedGlobalSearch();

        if (filled($this->globalSearch)) {
            app(TaskTelemetry::class)->startLookup();
        }
    }

    public function updatedSearch(mixed $value, ?string $key): void
    {
        parent::updatedSearch($value, $key);

        if (filled($value)) {
            app(TaskTelemetry::class)->startLookup();
        }
    }

    public function confirmDelete($id): void
    {
        Gate::authorize('delete', $this->scopedEmployee($id));
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete($payload): void
    {
        $employee = $this->scopedEmployee($payload['id']);
        Gate::authorize('delete', $employee);
        $employee->delete();

        $this->toast();
    }

    public function confirmToggleItemStatus($id): void
    {
        Gate::authorize('update', $this->scopedEmployee($id));
        $this->confirm('toggleItemStatusConfirmed', ['id' => $id], title: __('general.toggle-status'), message: __('general.confirm-status-change'), confirmLabel: __('general.save'), danger: false);
    }

    public function toggleItemStatusConfirmed($payload): void
    {
        $item = $this->scopedEmployee($payload['id']);
        Gate::authorize('update', $item);
        // A draft, or a volunteer awaiting their first contract, is activated
        // by that contract, never by hand (AC-B5-02, B5D-Q4; HR decision
        // 2026-10-23, which also covers older inactive records that never
        // had a contract). Former staff keep today's toggle.
        abort_if($item->isDraft() || $item->isAwaitingContract(), 403);
        $item->is_active = ! $item->is_active;
        $item->save();

        $this->toast(__('general.item-updated'));
    }

    public function exportExcel(): BinaryFileResponse
    {
        $query = $this->appliedQuery();
        $data = $query->get();

        $userSelectedColumns = $this->columns;

        $visibleColumnCodes = collect($this->resolveColumns())
            ->filter(fn ($column) => $column->isVisible())
            ->filter(function ($column) use ($userSelectedColumns) {
                if (empty($userSelectedColumns)) {
                    return true;
                }

                return in_array($column->code(), $userSelectedColumns);
            })
            ->filter(fn ($column) => $column->label() !== __('general.actions') && $column->code() !== 'photo')
            ->map(fn ($column) => $column->code())
            ->toArray();

        $filename = 'employees_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new EmployeeExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    /** Alpine expression the table view runs on a row click when link() is null. */
    public function rowClickAction(Model $model): string
    {
        return "\$dispatch('employee-drawer', { id: ".(int) $model->getKey().' })';
    }

    /** Below md each row becomes a card (B4-D §6). */
    public function cardView(): ?string
    {
        return 'livewire.employee.partials.card';
    }

    /** Inactive (former) staff are HR / general manager only (A2-D Q3). */
    public function canUseInactiveView(): bool
    {
        return (bool) auth()->user()?->activeRoleIs('HR', 'general-manager', 'super-admin');
    }

    /** Drafts are HR's work in progress: HR and super-admin only, not the general manager (B5-D §5.4). */
    public function canUseIncompleteView(): bool
    {
        return (bool) auth()->user()?->activeRoleIs('HR', 'super-admin');
    }

    /** The preset actually applied: an unknown or unauthorized value falls back to Active. */
    public function effectiveView(): string
    {
        return match (true) {
            in_array($this->preset, ['inactive', 'awaiting'], true) && $this->canUseInactiveView() => $this->preset,
            $this->preset === 'incomplete' && $this->canUseIncompleteView() => 'incomplete',
            default => 'active',
        };
    }

    #[On('employee-set-view')]
    public function setView(string $view): void
    {
        $this->preset = in_array($view, self::VIEWS, true) ? $view : 'active';
        $this->preset = $this->effectiveView();
        $this->resetPage();
        $this->dispatch('employee-view-changed', view: $this->preset);
    }

    /** Add action only for roles that may open the create route (employees.create). */
    protected function emptyStateCreate(): ?array
    {
        return auth()->user()?->hasActivePermission('employees.create')
            ? [__('general.state-add-employee'), route('employees.create')]
            : null;
    }

    /** Row click opens the read-only drawer, never the editor (B4D-Q2). */
    protected function link(Model $model): ?string
    {
        return null;
    }

    /** "Clear all" goes back to the default preset. */
    protected function clearPreset(): void
    {
        $this->preset = 'active';
        $this->dispatch('employee-view-changed', view: 'active');
    }

    /** The record, only if it lies inside the active role's city scope (404 otherwise). */
    protected function scopedEmployee(mixed $id): Employee
    {
        return Employee::query()
            ->inActiveCities(auth()->user())
            ->findOrFail($id);
    }

    protected function query(): Builder
    {
        return $this->model()::query()
            ->with([
                'contracts.position.jobLevel',
                'contracts.position.directManager.activeContracts',
                'account',
                'media',
            ])
            ->withExists(['realContracts as has_real_contract'])
            ->inActiveCities(auth()->user())
            ->tap(fn (Builder $q) => match ($this->effectiveView()) {
                // A draft is always inactive, so Active needs no extra condition.
                'active' => $q->where('employees.is_active', true),
                'inactive' => $q->completeRecords()->where('employees.is_active', false),
                'awaiting' => $q->awaitingContract(),
                'incomplete' => $q->drafts()->with('lastSavedBy.profile'),
            });
    }

    protected function globalSearchColumns(): array
    {
        return ['name', 'actual_dob'];
    }

    protected function managerOptions(): array
    {
        return Employee::query()
            ->select(['id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar'])
            ->inActiveCities(auth()->user())
            ->where('is_active', true)
            ->orderBy(transColumn('first_name'), 'asc')
            ->get()
            ->mapWithKeys(fn ($e) => [$e->id => $e->name])
            ->all();
    }

    protected function columns(): array
    {
        $user = auth()->user();
        $canUpdate = $user->hasActivePermission('employees.update');
        $canDelete = $user->hasActivePermission('employees.delete');
        $nationwide = $user->activeCityIds() === null;

        return [
            // Detail columns live in the picker only (hidden by default): B4-D §3.
            Column::make(__('employees.table.id'), 'id')->sortable()->hide(),

            // Identity: photo + name (opens the read-only drawer) + position.
            Column::make(trans('general.name'), 'employee')
                ->displayUsing(fn ($v, Model $m): string => view('livewire.employee.partials.identity', ['employee' => $m])->render())
                ->asHtml()
                ->sortable()
                ->searchable(function (Builder $query, $value) {
                    $parts = array_filter(explode(' ', trim($value)));

                    $query->where(function (Builder $q) use ($parts) {
                        foreach ($parts as $part) {
                            $q->where(function (Builder $inner) use ($part) {
                                $inner->where('first_name_en', 'LIKE', "%{$part}%")
                                    ->orWhere('first_name_ar', 'LIKE', "%{$part}%")
                                    ->orWhere('last_name_en', 'LIKE', "%{$part}%")
                                    ->orWhere('last_name_ar', 'LIKE', "%{$part}%");
                            });
                        }
                    });
                }),

            SelectColumn::make(trans('general.direct_manager'), 'direct_manager')
                ->displayUsing(function ($v, Model $model) {
                    return $model->primaryContract()?->position?->directManager?->employee()?->name ?? '—';
                })
                ->options($this->managerOptions())
                ->sortable()
                ->searchable(function (Builder $builder, mixed $search, SearchScope $scope): void {
                    $builder->whereHas('positions', function (Builder $builder) use ($search) {
                        $builder->whereHas('directManager', function (Builder $builder) use ($search) {
                            $builder->whereHas('contracts', function (Builder $builder) use ($search) {
                                $builder->whereHas('employee', function (Builder $builder) use ($search) {
                                    $builder->where('id', $search);
                                });
                            });
                        });
                    });
                }),

            Column::make(trans('general.work_email'), 'work_email')
                ->displayUsing(fn ($v, Model $m) => $m->account?->email
                    ? '<span dir="ltr" class="break-all">'.e($m->account->email).'</span>'
                    : '—')
                ->asHtml(),

            // Status as text as well as colour. The Active / Inactive choice is the
            // ?view= preset, so the column carries no filter of its own.
            SelectColumn::make(trans('general.is-active'), 'is_active')
                ->options([1 => __('general.active'), 0 => __('general.inactive')])
                ->displayUsing(fn ($v, Model $m): string => view('livewire.employee.partials.status', ['employee' => $m])->render())
                ->asHtml(),

            // A city column only helps roles that see more than one city.
            SelectColumn::make(trans_choice('general.city', 1), 'city.'.transColumn('name'))
                ->options(City::query()->whereNot('code', '000')->where('is_active', true)->get()->pluck(transColumn('name'), transColumn('name'))->toArray())
                ->sortable()->searchable()
                ->visible($nationwide),

            Column::make(trans_choice('general.role', 1), 'account')
                ->displayUsing(fn ($v, Model $m) => role_label($m->account?->myRole()))
                ->hide(),

            Column::make(trans('general.years-of-service'), 'years_of_service_formatted')->computed()->sortable()->hide(),

            // Month name in the page's own language, isolated with <bdi>: an
            // Arabic date then reads right to left as a whole, an English one
            // left to right, and neither gets reordered by the text around it.
            DateColumn::make(trans('general.join-at'), 'joined_at')
                ->displayUsing(fn ($v, Model $m) => $m->joined_at
                    ? '<bdi class="whitespace-nowrap">'.e($m->joined_at->copy()->locale(app()->getLocale())->translatedFormat('d M Y')).'</bdi>'
                    : '—')
                ->asHtml()
                ->sortable()
                ->hide(),

            SelectColumn::make(trans('general.position'), 'contracts.position.'.transColumn('title'))
                ->options(Position::query()->orderby(transColumn('title'), 'asc')->get()->pluck(transColumn('title'), transColumn('title'))->toArray())
                ->sortable()->searchable()
                ->hide(),

            // Main action (View profile) + a named "More" menu. Each menu item
            // calls an action that authorizes again on the server.
            Column::make(__('general.actions'), fn (Model $model): string => view('livewire.employee.partials.row-actions', [
                'employee' => $model,
                'canUpdate' => $canUpdate,
                'canDelete' => $canDelete,
            ])->render())->asHtml()->clickable(false),
        ];
    }
}
