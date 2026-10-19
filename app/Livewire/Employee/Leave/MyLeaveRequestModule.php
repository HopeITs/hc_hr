<?php

namespace App\Livewire\Employee\Leave;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\LeaveAlternativeEmployees;
use App\Services\LeaveNotificationService;
use Blade;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class MyLeaveRequestModule extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    public LeaveBalance $leaveBalance;

    public LeaveRequest $leaveRequest;

    public LeaveRequestForm $form;

    public $showModal = false;

    public $modalTitle = 'Modal title';

    public bool $detailsModal = false;

    public ?int $selectedLeaveId = null;

    #[Url(as: 'leave')]
    public ?int $linkedLeaveId = null;

    public string $statusFilter = 'all';

    public array $counts = [];

    protected $listeners = [
        'openRequestLeaveEdit' => 'edit',
        'openMyLeaveDetails' => 'openDetails',
        'processSave' => 'processSave',
    ];

    public function mount()
    {
        $this->refreshCounts();

        if ($this->linkedLeaveId !== null) {
            $this->openDetails($this->linkedLeaveId);
        }
    }

    public function setActiveTab(string $status)
    {
        $this->statusFilter = $status;
        $this->dispatch('$refresh');
    }

    public function refreshCounts(): void
    {
        $user = auth()->user();
        $employee = $user->profile;

        $leaveRequests = LeaveRequest::whereHas('leaveBalance.contract', function ($query) use ($employee) {
            $query->where('employee_id', $employee->id);
        });

        $this->counts = [
            'all' => (clone $leaveRequests)?->count(),
            'pending' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Pending)?->count(),
            'accepted' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Accepted)?->count(),
            'rejected' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Rejected)?->count(),
        ];
    }

    public function create()
    {
        $this->authorizeActivePermission('leaves.create');

        $this->form->reset();
        $this->modalTitle = __('general.add-new');
        $this->showModal = true;
    }

    public function edit(LeaveRequest $id)
    {
        $this->authorizeActivePermission('leaves.update');
        Gate::authorize('update', $id);

        $this->form->setLeaveRequest($id);

        $this->modalTitle = __('general.edit');
        $this->showModal = true;
    }

    #[Computed]
    public function selectedLeave(): ?LeaveRequest
    {
        if ($this->selectedLeaveId === null) {
            return null;
        }

        return $this->ownedLeaveRequests()
            ->with([
                'leaveBalance.leaveType',
                'leaveBalance.contract.position.center',
                'alternativeEmployee',
            ])
            ->find($this->selectedLeaveId);
    }

    public function openDetails(int $id): void
    {
        $leaveRequest = $this->ownedLeaveRequests()->findOrFail($id);
        Gate::authorize('view', $leaveRequest);

        $this->selectedLeaveId = $leaveRequest->id;
        $this->linkedLeaveId = $leaveRequest->id;
        unset($this->selectedLeave);

        $this->detailsModal = true;
    }

    public function closeDetails(): void
    {
        $this->detailsModal = false;
        $this->selectedLeaveId = null;
        $this->linkedLeaveId = null;
        unset($this->selectedLeave);
    }

    public function render()
    {
        $employee = auth()->user()->profile;
        $availableLeaves = $employee->availableLeaves();
        $allAvailableLeaves = $employee->allAvailableLeaves();
        $alternativeEmployees = app(LeaveAlternativeEmployees::class)->for($employee);

        return view('livewire.employee.leave.index', compact(
            'availableLeaves', 'allAvailableLeaves', 'alternativeEmployees',
        ));
    }

    public function confirmSave()
    {
        $html = $this->confirmationMessage();

        $this->confirm('processSave', title: __('general.confirmation'), html: $html, confirmLabel: __('general.save'), danger: false);

    }

    public function processSave()
    {
        $this->authorizeActivePermission($this->form->id ? 'leaves.update' : 'leaves.create');

        if ($this->form->id) {
            Gate::authorize('update', $this->form->leaveRequest);
            $this->form->update();
        } else {
            $this->form->store();

            app(LeaveNotificationService::class)->sendNewRequest(
                $this->form->leaveRequest,
                auth()->user(),
            );
            $this->dispatch('notification-created');
        }

        $this->showModal = false;
        $this->form->reset();

        $this->toast();

        $this->refreshCounts();
        $this->dispatch('leaveRequestSaved')->to(MyLeaveRequestTable::class);
    }

    protected function confirmationMessage(): string
    {
        $balance = $this->form->selectedBalance();
        $leaveTypeName = $balance->leaveType->name ?? 'N/A';
        $weekends = $balance->contract->position->center->weekends ?? '0,6';
        $type = $this->form->type;

        $start = $this->resolveStart();
        $end = $this->resolveEnd();
        $durationVal = $this->resolveDuration($weekends);
        $duration = $type === 'day' ? $durationVal.' '.trans_choice('general.day', $durationVal) : $durationVal.' '.trans_choice('general.hour', $durationVal);
        $date = $this->form->leave_date;
        $reason = $this->form->reason;

        return Blade::render(
            '<x-alerts.leave-request-confirmation
                :type="$type"
                :leave_type="$leaveTypeName"
                :start="$start"
                :end="$end"
                :duration="$duration"
                :reason="$reason"
                :date="$date"
            />',
            compact('type', 'leaveTypeName', 'start', 'end', 'duration', 'reason', 'date'),
        );
    }

    protected function resolveStart()
    {
        return $this->form->type === 'day'
            ? Carbon::parse($this->form->start_at)->format('d-m-Y')
            : $this->form->start_hour;
    }

    protected function resolveEnd()
    {
        return $this->form->type === 'day'
            ? Carbon::parse($this->form->end_at)->format('d-m-Y')
            : $this->form->end_hour;
    }

    protected function resolveDuration($weekends = '0,6')
    {
        if ($this->form->type === 'day') {
            $weekends = array_map('intval', explode(',', $weekends));

            return Carbon::parse($this->form->start_at)
                ->diffInDaysFiltered(function (Carbon $date) use ($weekends) {
                    return ! in_array($date->dayOfWeek, $weekends);
                }, $this->form->end_at) + 1;
        }

        return Carbon::parse($this->form->start_hour)
            ->floatDiffInHours(Carbon::parse($this->form->end_hour));
    }

    private function ownedLeaveRequests(): Builder
    {
        $employeeId = auth()->user()->profile->id;

        return LeaveRequest::query()
            ->whereHas(
                'leaveBalance.contract',
                fn (Builder $query) => $query->where('employee_id', $employeeId),
            );
    }
}
