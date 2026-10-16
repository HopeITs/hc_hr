<?php

namespace App\Livewire\ProgramManagement\DefaultDeliverable;

use App\Exports\DefaultDeliverableExport;
use App\Livewire\Concerns\ScopesToAuthorizedCity;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ChecklistItem;
use App\Models\City;
use App\Models\DefaultDeliverable as DefaultDeliverableModel;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DefaultDeliverablesTable extends LivewireTable
{
    use ScopesToAuthorizedCity;
    use WithFeedback;

    protected string $model = DefaultDeliverableModel::class;

    protected $listeners = [
        'delete',
        'refreshDefaultDeliverablesTable' => '$refresh',
        'exportExcel' => 'exportExcel',
    ];

    public function confirmDelete(int $id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        $model = DefaultDeliverableModel::findOrFail($payload['id']);

        // The id arrives from a browser-dispatched confirmation callback and
        // was previously deleted without any city check at all.
        Gate::authorize('delete', $model);

        $model->delete();

        $this->toast();

        $this->dispatch('refreshDefaultDeliverablesTable');
    }

    public function exportExcel(): BinaryFileResponse
    {
        Gate::authorize('viewAny', DefaultDeliverableModel::class);

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
            ->filter(fn ($column) => $column->label() !== __('general.actions'))
            ->map(fn ($column) => $column->code())
            ->toArray();

        $filename = 'default_deliverables_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new DefaultDeliverableExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function rowClickAction(Model $model): ?string
    {
        return auth()->user()?->can('update', $model)
            ? "\$dispatch('openDefaultDeliverableEdit', { id: {$model->getKey()} })"
            : null;
    }

    protected function query(): Builder
    {
        // Scoping the base query covers rows, search, filters, and the export,
        // which all derive from it.
        return $this->scopeToAuthorizedCity(
            $this->model()::query()->with(['employee', 'city', 'item']),
        );
    }

    protected function globalSearchColumns(): array
    {
        $loc = app()->getLocale();
        $name = $loc === 'ar' ? 'name_ar' : 'name_en';

        return [
            'id',
            "employee.$name",
            "city.$name",
            'item.name',
        ];
    }

    protected function employeeOptions(): array
    {
        $cityIds = $this->authorizedCityIds();

        return Employee::query()
            ->when($cityIds !== null, fn ($q) => $q->whereIn('city_id', $cityIds))
            ->whereHas('contracts', function ($query) {
                $query->whereHas('position', function ($subQuery) {
                    $subQuery->whereHas('department', function ($q) {
                        $q->whereIn('code', ['IT', 'CT', 'HR']);
                    });
                });
            })
            ->orderBy(transColumn('first_name'))
            ->get()
            ->mapWithKeys(fn ($e) => [$e->id => $e->name])
            ->toArray();
    }

    protected function cityOptions(): array
    {
        $loc = app()->getLocale();
        $name = $loc === 'ar' ? 'name_ar' : 'name_en';

        $cityIds = $this->authorizedCityIds();

        return City::query()
            ->when($cityIds !== null, fn ($q) => $q->whereKey($cityIds))
            ->whereHas('centers')
            ->pluck($name, $name)
            ->toArray();
    }

    protected function itemOptions(): array
    {
        return ChecklistItem::query()->orderBy('order')->pluck('name', 'name')->toArray();
    }

    protected function columns(): array
    {
        $loc = app()->getLocale();
        $name = $loc === 'ar' ? 'name_ar' : 'name_en';

        return [

            Column::make('ID', 'id')->sortable()->searchable(),

            // SelectColumn::make(trans_choice('general.employee', 1), 'employee.' . $name)
            //     ->options($this->employeeOptions())
            //     ->sortable()
            //     ->searchable(),
            SelectColumn::make(trans_choice('general.employee', 1), 'employee_id')
                ->options($this->employeeOptions())
                ->displayUsing(fn ($v, Model $m) => $m->employee?->name ?? '—')
                ->sortable()
                ->searchable(function (Builder $query, $value) {
                    $query->whereHas('employee', function ($q) use ($value) {
                        $q->where('id', $value);
                    });
                }),
            SelectColumn::make(trans_choice('general.city', 1), 'city.'.$name)
                ->options($this->cityOptions())
                ->sortable()
                ->searchable(),

            SelectColumn::make(__('general.item'), 'item.name')
                ->options(
                    ChecklistItem::orderBy('order')
                        ->pluck('name', 'name')
                        ->toArray(),
                )
                ->sortable()
                ->searchable(),

            Column::make(__('general.actions'), function (Model $m): string {
                // The policy decides, so the button and the action it triggers
                // can no longer disagree. (Hiding the button was never the
                // control — edit() and delete() enforce it too.)
                if (auth()->user()?->cannot('update', $m)) {
                    return '';
                }

                $id = $m->getKey();

                $editBtn = Blade::render(
                    '<x-table.edit-btn wire:click="$dispatch(\'openDefaultDeliverableEdit\', { id: '.$id.' })" />',
                );

                $deleteBtn = Blade::render(
                    '<x-table.delete-btn wire:click="confirmDelete('.$id.')" :modelId="'.$id.'" />',
                );

                return '<div class="flex items-center justify-center gap-2">'
                    .$editBtn.$deleteBtn.'</div>';
            })->asHtml()->clickable(false),
        ];
    }
}
