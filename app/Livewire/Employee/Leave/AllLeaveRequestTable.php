<?php

namespace App\Livewire\Employee\Leave;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Concerns\HasScreenListStates;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveRequestReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\DateColumn;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class AllLeaveRequestTable extends LivewireTable
{
    use HasScreenListStates;
    use WithFeedback;

    public LeaveRequestForm $form;

    public string $statusFilter = 'all';

    public $showModal = false;

    public $modalTitle = '';

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
        $this->confirm('delete', ['id' => $id]);
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

        $this->toast();
    }

    public function confirmHrReview(int $id): void
    {
        $leaveRequest = LeaveRequest::findOrFail($id);
        Gate::authorize('review', $leaveRequest);

        $this->confirm('markAsReviewed', ['id' => $id], title: __('general.review-leave-request'), message: __('general.confirm-review-leave-request'), confirmLabel: __('general.mark-reviewed'), danger: false);
    }

    public function markAsReviewed(array $payload): void
    {
        // One writer for "Seen by HR", shared with the work inbox (C3): it locks,
        // authorizes after the lock, writes and notifies after commit.
        app(LeaveRequestReview::class)->markSeen((int) $payload['id'], auth()->user());

        $this->dispatch('notification-created');
        $this->dispatch('$refresh');

        $this->toast(__('general.item-updated'));
    }

    /** Below md each request becomes a card (B4-D §6, G-B-4). */
    public function cardView(): ?string
    {
        return 'livewire.employee.leave.partials.all-card';
    }

    protected function rowOpenAction(Model $model): string
    {
        return $this->rowClickAction($model);
    }

    /** HR and super-admin see and set "Seen by HR". */
    protected function showsHrReview(): bool
    {
        $role = (string) auth()->user()?->myRole();

        return $role === 'super-admin' || str_starts_with($role, 'HR-');
    }

    /** The row's buttons, shared by the table's Actions column and the phone card. */
    protected function leaveActions(Model $model): string
    {
        $key = $model->getKey();
        $pending = $model->status === LeaveRequestStatus::Pending;

        $decisionBtn = $pending
            ? Blade::render('<x-table.decision-btn wire:click="$dispatch(\'openDecisionRequestLeave\', { id: '.$key.' })" />')
            : '';

        $showBtn = Blade::render('<x-table.show-btn wire:click="$dispatch(\'openShowRequestLeave\', { id: '.$key.' })" />');

        // Editing a decided request is deliberate: its own button, after View.
        $editBtn = ! $pending && auth()->user()?->hasActivePermission('leaves.update')
            ? Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openRequestLeaveEdit\', { id: '.$key.' })" />')
            : '';

        return $decisionBtn.$showBtn.$editBtn;
    }

    protected function emptyScreenState(): array
    {
        $user = auth()->user();
        // Mirrors the header button (HR / super-admin) and the permission create() enforces.
        $canCreate = $user && $user->activeRoleIs('HR', 'super-admin') && $user->hasActivePermission('leaves.create');

        return [
            'title' => __('states.leave-all-empty-title'),
            'description' => __('states.leave-all-empty-help'),
            'action' => $canCreate
                ? ['label' => __('general.add-new'), 'alpine' => "\$dispatch('openAllLeaveCreate')"]
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

    /**
     * Pending: the decision. Decided: the read-only details, never the editor
     * (B4D-Q2, G-B-5); editing is the separate Edit action.
     */
    protected function rowClickAction(Model $model): ?string
    {
        return $model->status === LeaveRequestStatus::Pending
            ? "\$dispatch('openDecisionRequestLeave', { id: {$model->getKey()} })"
            : "\$dispatch('openShowRequestLeave', { id: {$model->getKey()} })";
    }

    protected function query(): Builder
    {
        $user = auth()->user();

        $query = LeaveRequest::query()
            ->with(['leaveBalance.contract.employee', 'leaveBalance.contract.position.center', 'leaveBalance.leaveType'])
            ->manageableBy($user)
            // A former employee's pending request is nobody's work (G-C-11).
            ->exceptFormerStaffPending()
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
        return [

            SelectColumn::make(trans_choice('general.employee', 1), 'leaveBalance.contract.employee_id')
                ->options($this->employeeFilterOptions())
                ->displayUsing(fn ($v, Model $m) => '<bdi>'.e($m->leaveBalance?->contract?->employee?->name ?? '—').'</bdi>')
                ->asHtml()
                ->sortable()
                ->searchable(),
            SelectColumn::make(trans_choice('general.leave-type', 1), 'leaveBalance.leaveType.'.transColumn('name'))
                ->options(LeaveType::query()->pluck(transColumn('name'), transColumn('name'))->toArray())
                ->sortable()
                ->searchable(),
            SelectColumn::make(trans_choice('general.type', 1), 'type')
                ->options(['hour' => __('leaves.unit.hour'), 'day' => __('leaves.unit.day')])
                ->displayUsing(fn ($v, Model $m) => __('leaves.unit.'.($m->type === 'hour' ? 'hour' : 'day')))
                ->sortable()
                ->searchable(),

            Column::make(__('general.duration'), 'duration')->computed(),
            Column::make(__('general.status'), fn (Model $model): string => view('livewire.employee.leave.partials.card-status', ['item' => $model])->render())
                ->asHtml(),
            DateColumn::make(__('general.request_date'), 'created_at')
                ->format('d-m-Y H:i') // keep it; used for default/export/sort context
                ->displayUsing(function (mixed $value, Model $model): string {
                    if (! $value) {
                        return '-';
                    }

                    return '<bdi dir="ltr">'.e($model->created_at->format('d-m-Y')).'</bdi>';
                })
                ->sortable()->asHtml(),

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
            })->visible($this->showsHrReview())->asHtml()->clickable(false),
            Column::make(__('general.actions'), fn (Model $model): string => $this->leaveActions($model))
                ->asHtml()->clickable(false),

        ];
    }
}
