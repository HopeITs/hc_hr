<?php

namespace App\Livewire\Employee\Leave;

use App\Enums\LeaveRequestStatus;
use App\Exceptions\LeaveDecisionAlreadyMade;
use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\RemembersReturnTo;
use App\Livewire\Concerns\TracksTaskAttempt;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Employee\Leave\Concerns\FormatsLeaveDuration;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\LeaveAlternativeEmployees;
use App\Services\LeaveNotificationService;
use App\Services\LeaveRequestNextStep;
use App\Services\TeamCoverage;
use App\Support\Leave\LeaveQuote;
use Blade;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

class AllLeaveRequestModule extends Component
{
    use AuthorizesActivePermissions;
    use FormatsLeaveDuration;
    use GuardsModalClose;
    use RemembersReturnTo;
    use TracksTaskAttempt;
    use WithFeedback;

    public LeaveBalance $leaveBalance;

    public LeaveRequest $leaveRequest;

    public LeaveRequestForm $form;

    public $formModal = false;

    public $modalTitle = '';

    public string $statusFilter = 'all';

    public $selectedLeave;

    public $showModal = false;

    public $decisionModal = false;

    public $decisionLeave;

    #[Url(as: 'leave')]
    public ?int $linkedLeaveId = null;

    #[Url(as: 'mode')]
    public ?string $linkedLeaveMode = null;

    /** C2: ?new=1 (the header's New button) opens the new-request dialog once. */
    #[Url(as: 'new', except: false)]
    public bool $startNew = false;

    public array $counts = [];

    public $allAvailableLeaves = [];

    public $alternativeEmployees = [];

    protected $listeners = [
        'openRequestLeaveEdit' => 'edit',
        'openShowRequestLeave' => 'show',
        'openAllLeaveCreate' => 'create',
        'leaveStatusFilterCleared' => 'resetStatusFilter',
        'openDecisionRequestLeave' => 'openDecision',
        'processSave' => 'processSave',
    ];

    public function mount()
    {
        $this->refreshCounts();

        if ($this->linkedLeaveId !== null) {
            $leaveRequest = LeaveRequest::findOrFail($this->linkedLeaveId);

            // C3: HR is notified of every new request but decides only on a
            // manager's behalf, so a decision link it may not use opens the details.
            if (
                $this->linkedLeaveMode === 'decision'
                && $leaveRequest->status === LeaveRequestStatus::Pending
                && Gate::allows('decide', $leaveRequest)
            ) {
                $this->openDecision($leaveRequest);
            } else {
                $this->show($leaveRequest->id);
            }
        }

        if ($this->startNew) {
            $this->startNew = false;
            $this->create();
        }
    }

    public function show($id)
    {
        $leaveRequest = LeaveRequest::with(['leaveType', 'leaveBalance.contract.employee', 'alternativeEmployee'])->findOrFail($id);
        Gate::authorize('view', $leaveRequest);

        $this->selectedLeave = $leaveRequest;
        $this->linkedLeaveId = $leaveRequest->id;
        $this->linkedLeaveMode = 'details';

        $this->modalTitle = __('general.leave-details');
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
        $this->markClean('formModal');
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
        // A row or link from before someone decided it (G-B-1).
        if ($id->status !== LeaveRequestStatus::Pending && Gate::allows('view', $id)) {
            $this->showAlreadyDecided($id);

            return;
        }

        Gate::authorize('decide', $id);

        $this->form->setDecisionLeave($id);
        $this->decisionLeave = $id->load(['leaveType', 'leaveBalance.contract.employee', 'alternativeEmployee']);
        $this->linkedLeaveId = $id->id;
        $this->linkedLeaveMode = 'decision';

        $this->modalTitle = __('general.leave-decision');
        $this->markClean('decisionModal');
        $this->decisionModal = true;
        // X2-I task C: one attempt per opened decision.
        $this->startTaskAttempt('leave_review');
    }

    public function submitDecision()
    {
        try {
            $leaveRequest = $this->form->submitDecision();
        } catch (LeaveDecisionAlreadyMade $e) {
            // Nothing was written, and no notification goes out.
            $this->finishTaskAttempt('cancelled');
            $this->decisionModal = false;
            $this->decisionLeave = null;
            $this->showAlreadyDecided($e->leaveRequest);

            return;
        }

        $this->finishTaskAttempt();

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

    /**
     * X1-I: the browser lost the answer to submitDecision. The operation key is
     * the request itself (a request is decided once, under a row lock), so the
     * answer is its current state. A lookup only: nothing is decided here, and
     * someone who may no longer see the request gets the policy's 403.
     */
    public function reconcileOperation(): void
    {
        $leaveRequest = $this->decisionLeave
            ? LeaveRequest::query()->find($this->decisionLeave->id)
            : null;

        abort_if($leaveRequest === null, 404);
        Gate::authorize('view', $leaveRequest);

        if ((int) $leaveRequest->status === LeaveRequestStatus::Pending) {
            $this->toast(__('general.unknown-result.undecided'), type: 'info');

            return;
        }

        $this->decisionModal = false;
        $this->decisionLeave = null;
        $this->showAlreadyDecided($leaveRequest);
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
        $this->finishTaskAttempt('cancelled');
        $this->decisionModal = false;
        $this->decisionLeave = null;
        $this->clearLinkedLeave();
    }

    public function refreshCounts(): void
    {
        $user = auth()->user();
        // The same rows as the table below (AllLeaveRequestTable::query()).
        $leaveRequests = LeaveRequest::query()->manageableBy($user)->exceptFormerStaffPending();

        // A failed count must not take the page down: the table below shows the
        // load error with its retry, and the tabs fall back to zero until it recovers.
        try {
            $this->counts = ['all' => (clone $leaveRequests)->count(),
                'pending' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Pending)->count(),
                'accepted' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Accepted)->count(),
                'rejected' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Rejected)->count(), ];
        } catch (Throwable $e) {
            report($e);
            $this->counts = ['all' => 0, 'pending' => 0, 'accepted' => 0, 'rejected' => 0];
        }
    }

    /** The table's "clear filters" when only the status tab was narrowing the list. */
    public function resetStatusFilter(): void
    {
        $this->setActiveTab('all');
    }

    public function create()
    {
        // Also reached by the empty-state button's event, so check here, not in the view.
        $this->authorizeActivePermission('leaves.create');
        abort_unless(auth()->user()->activeRoleIs('HR', 'super-admin'), 403);

        $this->form->reset();
        $this->markClean('formModal');
        $this->formModal = true;
        $this->modalTitle = __('general.add-new');
    }

    public function render()
    {
        $employees = $this->scopedEmployees();
        $allAvailableLeaves = $this->allAvailableLeaves;
        $alternativeEmployees = $this->alternativeEmployees;

        return view('livewire.employee.leave.all', [
            ...compact('employees', 'allAvailableLeaves', 'alternativeEmployees'),
            'decisionOverlap' => $this->decisionOverlap(),
        ]);
    }

    public function confirmSave()
    {
        // Validate first: the confirmation needs a chosen leave balance, and
        // without this an empty form only reported "leave type is invalid"
        // instead of each missing field.
        $this->form->validate();
        // Pricing errors (balance, December, working hours ...) on their own
        // fields, from the same service store()/update() use under a lock.
        $quote = $this->form->checkedQuote();

        $html = $this->confirmationMessage($quote);

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

    /** The details dialog is read-only and keeps its ordinary close. */
    protected function guardedDialogs(): array
    {
        return ['formModal', 'decisionModal'];
    }

    protected function guardedEntries(string $dialog): array
    {
        return $dialog === 'decisionModal'
            ? $this->form->only(['status', 'reject_reason'])
            : $this->form->entries() + ['employee_id' => $this->form->employee_id];
    }

    protected function closeGuardedDialog(string $dialog): void
    {
        if ($dialog === 'decisionModal') {
            $this->closeDecision();
        } else {
            $this->formModal = false;
            $this->form->reset();
        }

        $this->form->resetValidation();

        // C2: a request started from New and closed unsent goes back to where New was pressed.
        if ($dialog !== 'decisionModal' && $this->returnTo !== null) {
            $this->redirect($this->returnTo, navigate: true);
        }
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

    /**
     * The duration comes from the pricing service. No balance figure is shown
     * here: this page is also used by managers, who see the leave type but
     * not balances (A3-D §9).
     */
    protected function confirmationMessage(?LeaveQuote $quote): string
    {
        $leaveTypeName = $this->form->selectedBalance()
            ->leaveType
            ->name ?? '—';

        $type = $this->form->type;

        $start = $this->resolveStart();
        $end = $this->resolveEnd();
        // No quote: a content-only edit of an accepted request, which keeps
        // its stored amount.
        $duration = $quote
            ? $this->formatLeaveSplit($quote->requestedHours, $quote->hoursPerDay)
            : ($this->form->leaveRequest?->duration ?? '—');
        $date = $this->form->leave_date;
        $reason = $this->form->reason;

        return Blade::render(
            '<x-leave.request-confirmation
                :type="$type"
                :leave-type="$leaveTypeName"
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

    /** C5: who else in the requester's team is away, for the open decision (advisory). */
    private function decisionOverlap(): ?array
    {
        if (! $this->decisionModal || ! $this->decisionLeave || (int) $this->decisionLeave->status !== LeaveRequestStatus::Pending) {
            return null;
        }

        try {
            return app(TeamCoverage::class)->teamOverlap($this->decisionLeave);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The approval contract's `already_handled` (§4.1): not an error. The
     * request opens read-only with its decision, a toast says who made it, and
     * the list and tab counts catch up with it.
     */
    private function showAlreadyDecided(LeaveRequest $leaveRequest): void
    {
        $this->show($leaveRequest->id);
        $this->toast(app(LeaveRequestNextStep::class)->alreadyDecided($leaveRequest, auth()->user()), 'info');

        $this->refreshCounts();
        $this->dispatch('leaveRequestDecisionSaved')->to(AllLeaveRequestTable::class);
    }

    private function clearLinkedLeave(): void
    {
        $this->linkedLeaveId = null;
        $this->linkedLeaveMode = null;
    }
}
