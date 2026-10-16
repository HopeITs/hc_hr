<?php

namespace App\Livewire\Basics;

use App\Enums\ChecklistItemOrder;
use App\Exports\ChecklistItemExport;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ChecklistItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ChecklistItemTable extends LivewireTable
{
    use WithFeedback;

    protected string $model = ChecklistItem::class;

    protected $listeners = [
        'refreshChecklistItemTable' => '$refresh',
        'delete',
        'exportExcel' => 'exportExcel',
    ];

    public function confirmDelete(int $id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        ChecklistItem::findOrFail($payload['id'])->delete();

        $this->toast();

        $this->dispatch('refreshChecklistItemTable');
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

        $filename = 'checklist_items_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new ChecklistItemExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function rowClickAction(Model $model): ?string
    {
        return auth()->user()?->hasActivePermission('project-basics.update')
            ? "\$dispatch('openChecklistItemEdit', { id: {$model->getKey()} })"
            : null;
    }

    protected function query(): Builder
    {
        return ChecklistItem::query();
    }

    protected function globalSearchColumns(): array
    {
        return ['id', 'name', 'order'];
    }

    protected function columns(): array
    {
        $canUpdate = auth()->user()?->hasActivePermission('project-basics.update') ?? false;
        $canDelete = auth()->user()?->hasActivePermission('project-basics.delete') ?? false;

        return [
            Column::make('ID', 'id')->sortable()->searchable(),

            Column::make(__('general.name'), 'name')
                ->sortable()
                ->searchable(),

            SelectColumn::make(__('general.order'), 'order')
                ->options([
                    ChecklistItemOrder::ProbationContract => ChecklistItemOrder::getLocalizedName(ChecklistItemOrder::ProbationContract)['value'],

                    ChecklistItemOrder::AnnualContract => ChecklistItemOrder::getLocalizedName(ChecklistItemOrder::AnnualContract)['value'],

                    ChecklistItemOrder::Resignation => ChecklistItemOrder::getLocalizedName(ChecklistItemOrder::Resignation)['value'],
                ])
                ->displayUsing(fn ($value) => ChecklistItemOrder::getLocalizedName($value)['value'])
                ->sortable()
                ->searchable(),

            Column::make(__('general.actions'), function (Model $m) use ($canUpdate, $canDelete): string {
                $id = $m->id;

                $editBtn = $canUpdate
                    ? Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openChecklistItemEdit\', { id: '.$id.' })" />')
                    : '';
                $deleteBtn = $canDelete
                    ? Blade::render('<x-table.delete-btn wire:click="confirmDelete('.$id.')" :modelId="'.$id.'" />')
                    : '';

                return '<div class="flex items-center justify-center gap-2">'.$editBtn.$deleteBtn.'</div>';
            })->asHtml()->visible($canUpdate || $canDelete)->clickable(false),
        ];
    }
}
