<?php

namespace App\Livewire\Employee\Leave;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use RamonRietdijk\LivewireTables\Columns\BooleanColumn;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\DateColumn;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class MyLeaveRequestTable extends LivewireTable
{
    use WithFeedback;

    public LeaveRequestForm $form;

    public string $statusFilter = 'all';

    public $showModal = false;

    public $modalTitle = 'Modal title';

    protected string $model = LeaveRequest::class;

    protected $listeners = ['filterStatus' => 'setStatusFilter', 'leaveRequestSaved' => '$refresh'];

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function confirmDelete($id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function edit($id)
    {
        $model = LeaveRequest::findOrFail($id);
        Gate::authorize('update', $model);

        $this->form->setLeaveRequest($model);
        $this->showModal = true;
    }

    public function delete($payload): void
    {
        $leaveRequest = LeaveRequest::findOrFail($payload['id']);
        Gate::authorize('delete', $leaveRequest);

        $leaveRequest->delete();

        $this->toast();
    }

    protected function rowClickAction(Model $model): ?string
    {
        return $model->status === LeaveRequestStatus::Pending
            ? "\$dispatch('openRequestLeaveEdit', { id: {$model->getKey()} })"
            : "\$dispatch('openMyLeaveDetails', { id: {$model->getKey()} })";
    }

    protected function query(): Builder
    {
        $user = auth()->user();
        $query = LeaveRequest::query()
            ->with(['leaveBalance.contract.employee', 'leaveBalance.contract.position'])
            ->orderBy('created_at', 'desc');

        $query->whereHas('leaveBalance.contract', function ($q) use ($user) {
            $q->where('employee_id', $user->profile->id);
        });

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        return $query;
    }

    protected function columns(): array
    {
        $user = auth()->user();

        return [

            SelectColumn::make(trans_choice('general.leave-type', 1), 'leaveBalance.leaveType.'.transColumn('name'))
                ->options(LeaveType::query()->pluck(transColumn('name'), transColumn('name'))->toArray())
                ->sortable()
                ->searchable(),
            SelectColumn::make(trans_choice('general.type', 1), 'type')
                ->options(['hour' => trans_choice('general.hour', 1), 'day' => trans_choice('general.day', 1)])
                ->displayUsing(fn ($v, Model $m) => trans_choice('general.'.$m->type, 1))
                ->sortable()
                ->searchable(),

            Column::make(__('general.duration'), 'duration')->computed(),
            Column::make(__('general.status'), function (mixed $value, Model $model): string {
                $status = LeaveRequestStatus::getLocalizedName($value->status);

                return Blade::render('<x-badge :color="$color">{{ $text }}</x-badge>', ['color' => $status['color'], 'text' => $status['value']]);
            })->asHtml(),

            DateColumn::make(__('general.start-date'), 'start_at')
                ->format('d-m-Y H:i') // keep it; used for default/export/sort context
                ->displayUsing(function (mixed $value, Model $model): string {
                    if (! $value) {
                        return '-';
                    }

                    return $model->type === 'hour' ? $model->start_at->format('H:i d-m-Y') : $model->start_at->format('d-m-Y');
                })
                ->sortable(),
            DateColumn::make(__('general.end-date'), 'end_at')
                ->format('d-m-Y H:i') // keep it; used for default/export/sort context
                ->displayUsing(function (mixed $value, Model $model): string {
                    if (! $value) {
                        return '-';
                    }

                    return $model->type === 'hour' ? $model->end_at->format('H:i d-m-Y') : $model->end_at->format('d-m-Y');
                })
                ->sortable(),
            BooleanColumn::make(__('general.seen-by-hr'), 'hr_approved'),
            Column::make(__('general.actions'), function (Model $model): string {
                $showBtn = Blade::render(
                    '<x-table.show-btn wire:click="$dispatch(\'openMyLeaveDetails\', { id: '.$model->getKey().' })" />',
                );

                if ($model->status === LeaveRequestStatus::Pending) {
                    $editBtn = Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openRequestLeaveEdit\', { id: '.$model->getKey().' })" />');
                    $deleteBtn = Blade::render(
                        '<x-table.delete-btn :modelId="'.$model->getKey().'" />',
                    );

                    return $showBtn.$editBtn.$deleteBtn;
                }

                return $showBtn;

            })->asHtml()->clickable(false),

        ];
    }
}
