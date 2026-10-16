<?php

namespace App\Livewire\Basics;

use App\Exports\ContractTypeExport;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ContractType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContractTypeTable extends LivewireTable
{
    use WithFeedback;

    protected string $model = ContractType::class;

    protected $listeners = [
        'refreshContractTypeTable' => '$refresh',
        'delete',
        'exportExcel' => 'exportExcel', // <-- ADD THIS LISTENER
    ];

    public function confirmDelete(int $id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        ContractType::findOrFail($payload['id'])->delete();

        $this->toast();

        $this->dispatch('refreshContractTypeTable');
    }

    public function exportExcel(): BinaryFileResponse
    {

        $query = $this->appliedQuery();

        $data = $query->get();

        $userSelectedColumns = $this->columns;

        // Get the static CODES instead of the translated LABELS
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

        $filename = 'contract_types_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new ContractTypeExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function rowClickAction(Model $model): ?string
    {
        return auth()->user()?->hasActivePermission('project-basics.update')
            ? "\$dispatch('openContractTypeEdit', { id: {$model->getKey()} })"
            : null;
    }

    protected function query(): Builder
    {
        return ContractType::query();
    }

    protected function globalSearchColumns(): array
    {
        return ['id', 'name_ar', 'name_en'];
    }

    protected function columns(): array
    {
        $canUpdate = auth()->user()?->hasActivePermission('project-basics.update') ?? false;
        $canDelete = auth()->user()?->hasActivePermission('project-basics.delete') ?? false;

        return [

            Column::make('ID', 'id')->sortable()->searchable(),

            Column::make(__('general.name').' (AR)', 'name_ar')
                ->sortable()
                ->searchable(),

            Column::make(__('general.name').' (EN)', 'name_en')
                ->sortable()
                ->searchable(),

            Column::make(__('general.actions'), function (Model $m) use ($canUpdate, $canDelete): string {

                $id = $m->id;

                $buttons = [];

                if ($canUpdate) {
                    $buttons[] = Blade::render(
                        '<x-table.edit-btn wire:click="$dispatch(\'openContractTypeEdit\', { id: '.$id.' })" />',
                    );
                }

                if ($canDelete) {
                    $buttons[] = Blade::render(
                        '<x-table.delete-btn wire:click="confirmDelete('.$id.')" :modelId="'.$id.'" />',
                    );
                }

                return '<div class="flex items-center justify-center gap-2">'.implode('', $buttons).'</div>';
            })->asHtml()->visible($canUpdate || $canDelete)->clickable(false),
        ];
    }
}
