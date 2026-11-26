<?php

namespace App\Livewire\Employee\Leave;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Concerns\HasScreenListStates;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveRequestReview;
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
    use HasScreenListStates;
    use WithFeedback;

    public LeaveRequestForm $form;

    public string $statusFilter = 'all';

    public $showModal = false;

    public $modalTitle = '';

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

    public function confirmCancel(int $id): void
    {
        Gate::authorize('cancel', LeaveRequest::findOrFail($id));

        $this->confirm(
            'cancelLeave',
            ['id' => $id],
            title: __('leaves.cancel.title'),
            message: __('leaves.cancel.message'),
            confirmLabel: __('leaves.cancel.confirm'),
        );
    }

    /** An accepted leave that has not started is withdrawn, not deleted: its hours go back (HR-027). */
    public function cancelLeave(array $payload): void
    {
        app(LeaveRequestReview::class)->cancel((int) $payload['id'], auth()->user());

        $this->dispatch('leaveRequestSaved');
        $this->toast(__('leaves.cancel.done'));
    }

    /** Below md each request becomes a card (B4-D §6, G-B-4). */
    public function cardView(): ?string
    {
        return 'livewire.employee.leave.partials.my-card';
    }

    protected function cancelButton(Model $model): string
    {
        if (! Gate::allows('cancel', $model)) {
            return '';
        }

        return Blade::render(
            '<button type="button" wire:click="confirmCancel('.$model->getKey().')" aria-label="'.e(__('leaves.cancel.action')).'" title="'.e(__('leaves.cancel.action')).'" class="cursor-pointer text-ink-2 p-2 inline-flex items-center max-sm:min-h-11 max-sm:min-w-11 justify-center"><x-ic name="x" class="size-4.5" /></button>',
        );
    }

    protected function rowOpenAction(Model $model): string
    {
        return $this->rowClickAction($model);
    }

    /** The row's buttons, shared by the table's Actions column and the phone card. */
    protected function leaveActions(Model $model): string
    {
        $showBtn = Blade::render(
            '<x-table.show-btn wire:click="$dispatch(\'openMyLeaveDetails\', { id: '.$model->getKey().' })" />',
        );

        if ($model->status !== LeaveRequestStatus::Pending) {
            return $showBtn.$this->cancelButton($model);
        }

        $editBtn = Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openRequestLeaveEdit\', { id: '.$model->getKey().' })" />');
        $deleteBtn = Blade::render('<x-table.delete-btn :modelId="'.$model->getKey().'" />');

        return $showBtn.$editBtn.$deleteBtn;
    }

    protected function emptyScreenState(): array
    {
        // Same permission the create handler enforces; a role without it gets text only.
        $canCreate = auth()->user()?->hasActivePermission('leaves.create') ?? false;

        return [
            'title' => __('states.leave-empty-title'),
            'description' => __($canCreate ? 'states.leave-empty-help' : 'states.leave-empty-help-readonly'),
            'action' => $canCreate
                ? ['label' => __('leaves.modal.new-title'), 'alpine' => "\$dispatch('openRequestLeaveCreate')"]
                : null,
        ];
    }

    protected function narrowedByTab(): bool
    {
        return $this->statusFilter !== 'all';
    }

    protected function clearTab(): void
    {
        $this->dispatch('leaveStatusFilterCleared');
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
            ->with(['leaveBalance.contract.employee', 'leaveBalance.contract.position.center', 'leaveBalance.leaveType'])
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
            SelectColumn::make(__('leaves.unit.column'), 'type')
                ->options(['hour' => __('leaves.unit.hour'), 'day' => __('leaves.unit.day')])
                ->displayUsing(fn ($v, Model $m) => __('leaves.unit.'.($m->type === 'hour' ? 'hour' : 'day')))
                ->sortable()
                ->searchable(),

            Column::make(__('general.duration'), 'duration')->computed(),
            Column::make(__('general.status'), fn (Model $model): string => view('livewire.employee.leave.partials.card-status', ['item' => $model])->render())
                ->asHtml(),

            DateColumn::make(__('general.start-date'), 'start_at')
                ->format('d-m-Y H:i') // keep it; used for default/export/sort context
                ->displayUsing(function (mixed $value, Model $model): string {
                    if (! $value) {
                        return '-';
                    }

                    return '<bdi dir="ltr">'.e($model->type === 'hour' ? $model->start_at->format('H:i d-m-Y') : $model->start_at->format('d-m-Y')).'</bdi>';
                })
                ->sortable()->asHtml(),
            DateColumn::make(__('general.end-date'), 'end_at')
                ->format('d-m-Y H:i') // keep it; used for default/export/sort context
                ->displayUsing(function (mixed $value, Model $model): string {
                    if (! $value) {
                        return '-';
                    }

                    return '<bdi dir="ltr">'.e($model->type === 'hour' ? $model->end_at->format('H:i d-m-Y') : $model->end_at->format('d-m-Y')).'</bdi>';
                })
                ->sortable()->asHtml(),
            BooleanColumn::make(__('general.seen-by-hr'), 'hr_approved'),
            Column::make(__('general.actions'), fn (Model $model): string => $this->leaveActions($model))
                ->asHtml()->clickable(false),

        ];
    }
}
