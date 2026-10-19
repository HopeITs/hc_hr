<?php

namespace App\Livewire\Employee\Leave;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\LeaveAlternativeEmployees;
use App\Services\LeaveNotificationService;
use Blade;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

class AllLeaveRequestModule extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    public LeaveBalance $leaveBalance;

    public LeaveRequest $leaveRequest;

    public LeaveRequestForm $form;

    public $formModal = false;

    public $modalTitle = 'Modal title';

    public string $statusFilter = 'all';

    public $selectedLeave;

    public $showModal = false;

    public $decisionModal = false;

    public $decisionLeave;

    #[Url(as: 'leave')]
    public ?int $linkedLeaveId = null;

    #[Url(as: 'mode')]
    public ?string $linkedLeaveMode = null;

    public array $counts = [];

    public $allAvailableLeaves = [];

    public $alternativeEmployees = [];

    protected $listeners = [
        'openRequestLeaveEdit' => 'edit',
        'openShowRequestLeave' => 'show',
        'openDecisionRequestLeave' => 'openDecision',
        'processSave' => 'processSave',
    ];

    public function mount()
    {
        $this->refreshCounts();

        if ($this->linkedLeaveId !== null) {
            $leaveRequest = LeaveRequest::findOrFail($this->linkedLeaveId);

            if (
                $this->linkedLeaveMode === 'decision'
                && $leaveRequest->status === LeaveRequestStatus::Pending
            ) {
                $this->openDecision($leaveRequest);
            } else {
                $this->show($leaveRequest->id);
            }
        }
    }

    public function show($id)
    {
        $leaveRequest = LeaveRequest::with(['leaveType', 'leaveBalance.contract.employee', 'alternativeEmployee'])->findOrFail($id);
        Gate::authorize('view', $leaveRequest);

        $this->selectedLeave = $leaveRequest;
        $this->linkedLeaveId = $leaveRequest->id;
        $this->linkedLeaveMode = 'details';

        $this->modalTitle = 'Leave Details';
        $this->showModal = true;
    }

    public function edit(LeaveRequest $id)
    {
        Gate::authorize('update', $id);

        $this->form->setLeaveRequest($id);

        $employee = $id->leaveBalance->contract->employee;

        // hydrate dependent lists
        $this->allAvailableLeaves = $this->leavesFor($employee);
        $this->alternativeEmployees = $this->alternativeEmployeesFor($employee);

        // hydrate form values
        $this->form->employee_id = $employee->id;
        $this->form->leave_balance_id = (int) $id->leave_balance_id;

        $this->modalTitle = __('general.edit');
        $this->formModal = true;
    }

    public function updatedFormEmployeeId($employeeId)
    {
        if (! $employeeId) {
            $this->allAvailableLeaves = [];
            $this->alternativeEmployees = [];
            $this->form->leave_balance_id = null;

            return;
        }

        $employee = Employee::with('city')->find($employeeId);

        $this->allAvailableLeaves = $this->leavesFor($employee);
        $this->alternativeEmployees = $this->alternativeEmployeesFor($employee);

        // Reset dependent dropdowns
        $this->form->leave_balance_id = null;
        $this->form->alternative_employee = null;
    }

    public function openDecision(LeaveRequest $id)
    {
        Gate::authorize('decide', $id);

        $this->form->setDecisionLeave($id);
        $this->decisionLeave = $id->load(['leaveType', 'leaveBalance.contract.employee', 'alternativeEmployee']);
        $this->linkedLeaveId = $id->id;
        $this->linkedLeaveMode = 'decision';

        $this->modalTitle = __('general.leave-decision');
        $this->decisionModal = true;
    }

    public function submitDecision()
    {
        $leaveRequest = $this->form->submitDecision();

        app(LeaveNotificationService::class)->sendDecision(
            $leaveRequest,
            auth()->user(),
        );
        $this->dispatch('notification-created');

        $this->refreshCounts();
        $this->decisionModal = false;
        $this->clearLinkedLeave();

        $this->toast();

        $this->dispatch('$refresh');
        $this->dispatch('leaveRequestDecisionSaved')->to(AllLeaveRequestTable::class);
    }

    public function setActiveTab(string $status)
    {
        $this->statusFilter = $status;
        $this->dispatch('$refresh');
    }

    public function closeDetails(): void
    {
        $this->showModal = false;
        $this->selectedLeave = null;
        $this->clearLinkedLeave();
    }

    public function closeDecision(): void
    {
        $this->decisionModal = false;
        $this->decisionLeave = null;
        $this->clearLinkedLeave();
    }

    public function refreshCounts(): void
    {
        $user = auth()->user();
        $leaveRequests = LeaveRequest::query()->manageableBy($user);

        $this->counts = ['all' => (clone $leaveRequests)->count(),
            'pending' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Pending)->count(),
            'accepted' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Accepted)->count(),
            'rejected' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Rejected)->count(), ];
    }

    public function create()
    {
        $this->form->reset();
        $this->formModal = true;
        $this->modalTitle = __('general.add-new');
    }

    public function render()
    {
        $employees = $this->scopedEmployees();
        $allAvailableLeaves = $this->allAvailableLeaves;
        $alternativeEmployees = $this->alternativeEmployees;

        return view('livewire.employee.leave.all', compact('employees', 'allAvailableLeaves', 'alternativeEmployees'));
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
        }

        $this->formModal = false;
        $this->form->reset();

        $this->toast();

        $this->refreshCounts();
    }

    public function confirmDelete($id)
    {

        $this->confirm('delete', ['id' => $id]);
    }

    public function delete($id)
    {
        $leaveRequest = LeaveRequest::findOrFail($id['id']);
        Gate::authorize('delete', $leaveRequest);

        $leaveRequest->delete();
        $this->showModal = false;
        $this->clearLinkedLeave();
        $this->refreshCounts();
        $this->toast();

    }

    /**
     * Employees selectable in the dropdown, scoped to the HR user's city.
     * Computed per render (not stored as state) so the heavy list is never
     * serialized into the Livewire payload on every request.
     */
    protected function scopedEmployees(): Collection
    {
        $user = auth()->user();

        if (! str_contains($user->myRole(), 'HR-')) {
            return collect();
        }

        $cityIds = $user->activeCityIds();

        return Employee::query()
            ->when($cityIds !== null, fn ($q) => $q->whereIn('city_id', $cityIds))
            ->orderBy(transColumn('first_name'))
            ->get();
    }

    protected function confirmationMessage(): string
    {
        $leaveTypeName = $this->form->selectedBalance()
            ->leaveType
            ->name ?? 'N/A';

        $type = $this->form->type;

        $start = $this->resolveStart();
        $end = $this->resolveEnd();
        $duration = $this->resolveDuration();
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

    /**
     * Leave balances selectable for an employee's latest active contract.
     * Single source of truth for the leave-type dropdown (with leaveType
     * eager-loaded for the option labels).
     */
    protected function leavesFor(?Employee $employee): Collection
    {
        return $employee?->latestActiveContract()
            ?->leaveBalances()
            ->with('leaveType')
            ->get() ?? collect();
    }

    protected function alternativeEmployeesFor(?Employee $employee): Collection
    {
        return $employee ? app(LeaveAlternativeEmployees::class)->for($employee) : collect();
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

    protected function resolveDuration()
    {
        if ($this->form->type === 'day') {
            return Carbon::parse($this->form->start_at)
                ->diffInDays(Carbon::parse($this->form->end_at)) + 1;
        }

        return Carbon::parse($this->form->start_hour)
            ->floatDiffInHours(Carbon::parse($this->form->end_hour));
    }

    private function clearLinkedLeave(): void
    {
        $this->linkedLeaveId = null;
        $this->linkedLeaveMode = null;
    }
}
