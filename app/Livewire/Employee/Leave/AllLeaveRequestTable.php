<?php

namespace App\Livewire\Employee\Leave;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\DateColumn;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class AllLeaveRequestTable extends LivewireTable
{
    public LeaveRequestForm $form;

    public string $statusFilter = 'all';

    public $showModal = false;

    public $modalTitle = 'Modal title';

    protected string $model = LeaveRequest::class;

    protected $listeners = [
        'filterStatus' => 'setStatusFilter',
        'leaveRequestDecisionSaved' => 'refreshTable',
    ];

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function refreshTable(): void {}

    public function confirmDelete($id): void
    {
        LivewireAlert::title(__('general.delete-item'))
            ->text(__('general.confirm-delete-item'))
            ->question()
            ->withConfirmButton(__('general.delete'))
            ->onConfirm('delete', ['id' => $id])
            ->withCancelButton(__('general.cancel'))
            ->show();
    }

    public function edit($id)
    {
        $model = LeaveRequest::findOrFail($id);
        Gate::authorize('update', $model);

        $this->form->setLeaveRequest($model);
        $employee = auth()->user()->profile;
        $modalTitle = __('general.edit');
        $form = $this->form;
        $availableLeaves = auth()->user()->profile->availableLeaves();
        $allAvailableLeaves = $employee->allAvailableLeaves();
        $alternativeEmployees = Employee::where('city_id', $employee->city_id)->where('id', '!=', $employee->id)
            ->whereHas('contracts', function ($query) use ($employee) {
                $query->whereHas('position.department', function ($query) use ($employee) {
                    $query->where('id', $employee->department->id ?? null);
                });
            })->get();
        $this->showModal = true;
    }

    public function delete($payload): void
    {
        $leaveRequest = LeaveRequest::findOrFail($payload['id']);
        Gate::authorize('delete', $leaveRequest);

        $leaveRequest->delete();

        LivewireAlert::title(__('general.success-message'))
            ->success()->toast()->position('top-start')->show();
    }

    public function confirmHrReview(int $id): void
    {
        $leaveRequest = LeaveRequest::findOrFail($id);
        Gate::authorize('review', $leaveRequest);

        LivewireAlert::title(__('general.review-leave-request'))
            ->text(__('general.confirm-review-leave-request'))
            ->question()
            ->withConfirmButton(__('general.mark-reviewed'))
            ->onConfirm('markAsReviewed', ['id' => $id])
            ->withCancelButton(__('general.cancel'))
            ->show();
    }

    public function markAsReviewed(array $payload): void
    {
        $item = DB::transaction(function () use ($payload): LeaveRequest {
            $leaveRequest = LeaveRequest::query()
                ->lockForUpdate()
                ->findOrFail($payload['id']);

            // Authorization runs after acquiring the lock so a concurrent
            // request cannot pass with a stale, unreviewed model.
            Gate::authorize('review', $leaveRequest);

            $leaveRequest->update(['hr_approved' => true]);

            return $leaveRequest;
        });

        app(LeaveNotificationService::class)->sendHrReview(
            $item,
            auth()->user(),
        );
        $this->dispatch('notification-created');
        $this->dispatch('$refresh');

        LivewireAlert::title(__('general.item-updated'))
            ->success()->toast()->position('top-start')->show();
    }

    protected function rowClickAction(Model $model): ?string
    {
        return $model->status === LeaveRequestStatus::Pending
            ? "\$dispatch('openDecisionRequestLeave', { id: {$model->getKey()} })"
            : "\$dispatch('openRequestLeaveEdit', { id: {$model->getKey()} })";
    }

    protected function query(): Builder
    {
        $user = auth()->user();

        $query = LeaveRequest::query()
            ->with(['leaveBalance.contract.employee', 'leaveBalance.contract.position'])
            ->manageableBy($user)
            ->orderBy('created_at', 'desc');

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        return $query;
    }

    /**
     * Employees appearing in the leave requests this user can manage.
     *
     * Built through the same manageableBy scope as query(), so the header
     * filter never offers employees the viewer cannot see anyway (a manager
     * only gets their own reports, HR-{city} only that city).
     *
     * @return array<int, string>
     */
    protected function employeeFilterOptions(): array
    {
        $employeeIds = Contract::query()
            ->whereIn(
                'id',
                LeaveBalance::query()
                    ->whereIn(
                        'id',
                        LeaveRequest::query()
                            ->manageableBy(auth()->user())
                            ->select('leave_balance_id'),
                    )
                    ->select('contract_id'),
            )
            ->distinct()
            ->pluck('employee_id');

        return Employee::query()
            ->whereIn('id', $employeeIds)
            ->select(['id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar'])
            ->orderBy(transColumn('first_name'))
            ->get()
            ->mapWithKeys(fn ($e) => [$e->id => $e->name])
            ->toArray();
    }

    protected function columns(): array
    {
        $user = auth()->user();

        return [

            SelectColumn::make(trans_choice('general.employee', 1), 'leaveBalance.contract.employee_id')
                ->options($this->employeeFilterOptions())
                ->displayUsing(fn ($v, Model $m) => $m->leaveBalance?->contract?->employee?->name ?? '—')
                ->sortable()
                ->searchable(),
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

                return "<span class='px-2 py-1 text-xs font-semibold rounded-md bg-{$status['color']}-100 text-{$status['color']}-800'>
                    {$status['value']}
                </span>";
            })->asHtml(),
            DateColumn::make(__('general.request_date'), 'created_at')
                ->format('d-m-Y H:i') // keep it; used for default/export/sort context
                ->displayUsing(function (mixed $value, Model $model): string {
                    if (! $value) {
                        return '-';
                    }

                    return $model->created_at->format('d-m-Y');
                })
                ->sortable(),

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
            Column::make(__('general.seen-by-hr'), function (Model $model): string {
                if ($model->status === LeaveRequestStatus::Pending) {
                    return '';
                }

                return Blade::render(
                    '<x-table.hr-review
                        :reviewed="$reviewed"
                        :model-id="$modelId"
                    />',
                    [
                        'reviewed' => $model->hr_approved,
                        'modelId' => $model->getKey(),
                    ],
                );
            })->visible(
                $user->myRole() === 'super-admin'
                || str_starts_with((string) $user->myRole(), 'HR-'),
            )->asHtml()->clickable(false),
            Column::make(__('general.actions'), function (Model $model): string {

                $decisionBtn = '';
                $editBtn = '';

                if ($model->status === LeaveRequestStatus::Pending) {
                    $decisionBtn = Blade::render('<x-table.decision-btn wire:click="$dispatch(\'openDecisionRequestLeave\', { id: '.$model->getKey().' })" />');
                }
                if ($model->status !== LeaveRequestStatus::Pending) {
                    $editBtn = Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openRequestLeaveEdit\', { id: '.$model->getKey().' })" />');
                }

                $showBtn = Blade::render('<x-table.show-btn wire:click="$dispatch(\'openShowRequestLeave\', { id: '.$model->getKey().' })" />');

                return $decisionBtn.$editBtn.$showBtn;
            })->asHtml()->clickable(false),

        ];
    }
}
