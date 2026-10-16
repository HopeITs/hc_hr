<?php

namespace App\Livewire\Basics;

use App\Enums\LeaveType as LeaveTypeEnum;
use App\Exports\LeaveTypeExport;
use App\Livewire\Concerns\WithFeedback;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LeaveTypeTable extends LivewireTable
{
    use WithFeedback;

    protected string $model = LeaveType::class;

    protected $listeners = [
        'refreshLeaveTypeTable' => '$refresh',
        'delete',
        'exportExcel' => 'exportExcel',
    ];

    public function confirmDelete(int $id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        LeaveType::findOrFail($payload['id'])->delete();

        $this->toast();

        $this->dispatch('refreshLeaveTypeTable');
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

        $filename = 'leave_types_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new LeaveTypeExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function rowClickAction(Model $model): ?string
    {
        return auth()->user()?->hasActivePermission('project-basics.update')
            ? "\$dispatch('openLeaveTypeEdit', { id: {$model->getKey()} })"
            : null;
    }

    protected function query(): Builder
    {
        return LeaveType::query();
    }

    protected function globalSearchColumns(): array
    {
        return ['id', 'name_ar', 'name_en', 'base_days', 'allowed_gender', 'marital_status'];
    }

    protected function columns(): array
    {
        $canUpdate = auth()->user()?->hasActivePermission('project-basics.update') ?? false;
        $canDelete = auth()->user()?->hasActivePermission('project-basics.delete') ?? false;

        return [
            Column::make('ID', 'id')->sortable()->searchable(),

            Column::make(__('general.name').' (AR)', 'name_ar')->sortable()->searchable(),
            Column::make(__('general.name').' (EN)', 'name_en')->sortable()->searchable(),

            Column::make(__('general.base-days'), 'base_days')->sortable()->searchable(),

            SelectColumn::make(__('general.allowed-gender'), 'allowed_gender')
                ->options([
                    LeaveTypeEnum::GENDER_ANY => LeaveTypeEnum::getGender(LeaveTypeEnum::GENDER_ANY)['value'],
                    LeaveTypeEnum::GENDER_MALE => LeaveTypeEnum::getGender(LeaveTypeEnum::GENDER_MALE)['value'],
                    LeaveTypeEnum::GENDER_FEMALE => LeaveTypeEnum::getGender(LeaveTypeEnum::GENDER_FEMALE)['value'],
                ])
                ->displayUsing(fn ($value) => LeaveTypeEnum::getGender($value)['value'])
                ->sortable()
                ->searchable(),

            SelectColumn::make(__('general.marital_status'), 'marital_status')
                ->options([
                    LeaveTypeEnum::MARITAL_STATUS_ANY => LeaveTypeEnum::getMaritalStatus(LeaveTypeEnum::MARITAL_STATUS_ANY)['value'],
                    LeaveTypeEnum::MARITAL_STATUS_SINGLE => LeaveTypeEnum::getMaritalStatus(LeaveTypeEnum::MARITAL_STATUS_SINGLE)['value'],
                    LeaveTypeEnum::MARITAL_STATUS_MARRIED => LeaveTypeEnum::getMaritalStatus(LeaveTypeEnum::MARITAL_STATUS_MARRIED)['value'],
                ])
                ->displayUsing(fn ($value) => LeaveTypeEnum::getMaritalStatus($value)['value'])
                ->sortable()
                ->searchable(),

            Column::make(__('general.actions'), function (Model $m) use ($canUpdate, $canDelete): string {
                $id = $m->id;
                $editBtn = $canUpdate
                    ? Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openLeaveTypeEdit\', { id: '.$id.' })" />')
                    : '';
                $deleteBtn = $canDelete
                    ? Blade::render('<x-table.delete-btn wire:click="confirmDelete('.$id.')" :modelId="'.$id.'" />')
                    : '';

                return '<div class="flex items-center justify-center gap-2">'.$editBtn.$deleteBtn.'</div>';
            })->asHtml()->visible($canUpdate || $canDelete)->clickable(false),
        ];
    }
}
