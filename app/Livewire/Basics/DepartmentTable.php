<?php

namespace App\Livewire\Basics;

use App\Exports\DepartmentExport;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DepartmentTable extends LivewireTable
{
    use WithFeedback;

    protected string $model = Department::class;

    protected $listeners = [
        'refreshDepartmentTable' => '$refresh',
        'delete',
        'exportExcel' => 'exportExcel',
    ];

    public function confirmDelete(int $id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        Department::findOrFail($payload['id'])->delete();

        $this->toast();

        $this->dispatch('refreshDepartmentTable');
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
            ->filter(fn ($column) => $column->label() !== __('general.actions'))
            ->map(fn ($column) => $column->code())
            ->toArray();

        $filename = 'departments_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new DepartmentExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function rowClickAction(Model $model): ?string
    {
        return auth()->user()?->hasActivePermission('project-basics.update')
            ? "\$dispatch('openDepartmentEdit', { id: {$model->getKey()} })"
            : null;
    }

    protected function query(): Builder
    {
        return Department::query();
    }

    protected function globalSearchColumns(): array
    {
        return [
            'id',
            'name',
            'code',
            'description_ar',
            'description_en',
            'email',
        ];
    }

    protected function columns(): array
    {
        $canUpdate = auth()->user()?->hasActivePermission('project-basics.update') ?? false;
        $canDelete = auth()->user()?->hasActivePermission('project-basics.delete') ?? false;

        return [
            Column::make('ID', 'id')
                ->sortable()
                ->searchable(),

            Column::make(__('general.department-name'), 'name')
                ->sortable()
                ->searchable(),

            Column::make(__('general.code'), 'code')
                ->sortable()
                ->searchable(),

            // Column::make(__('general.department-description') . ' (EN)', 'description_en')
            //     ->sortable()
            //     ->searchable(),

            //     Column::make(__('general.department-description') . ' (AR)', 'description_ar')
            //     ->sortable()
            //     ->searchable(),

            Column::make(__('general.department-email'), 'email')
                ->sortable()
                ->searchable(),

            Column::make(__('general.actions'), function (Model $m) use ($canUpdate, $canDelete): string {
                $id = $m->id;

                $editBtn = $canUpdate
                    ? Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openDepartmentEdit\', { id: '.$id.' })" />')
                    : '';
                $deleteBtn = $canDelete
                    ? Blade::render('<x-table.delete-btn wire:click="confirmDelete('.$id.')" :modelId="'.$id.'" />')
                    : '';

                return '<div class="flex items-center justify-center gap-2">'.$editBtn.$deleteBtn.'</div>';
            })->asHtml()->visible($canUpdate || $canDelete)->clickable(false),
        ];
    }
}
