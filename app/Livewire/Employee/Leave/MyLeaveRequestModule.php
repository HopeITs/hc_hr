<?php

namespace App\Livewire\Employee\Leave;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\RemembersReturnTo;
use App\Livewire\Concerns\TracksTaskAttempt;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Employee\Leave\Concerns\FormatsLeaveDuration;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\LeaveAlternativeEmployees;
use App\Services\LeaveNotificationService;
use App\Services\TeamCoverage;
use App\Support\Leave\LeaveQuote;
use Blade;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

class MyLeaveRequestModule extends Component
{
    use AuthorizesActivePermissions;
    use FormatsLeaveDuration;
    use GuardsModalClose;
    use HandlesLoadFailure;
    use RemembersReturnTo;
    use TracksTaskAttempt;
    use WithFeedback;

    public LeaveBalance $leaveBalance;

    public LeaveRequest $leaveRequest;

    public LeaveRequestForm $form;

    public $showModal = false;

    public $modalTitle = '';

    public bool $detailsModal = false;

    public ?int $selectedLeaveId = null;

    #[Url(as: 'leave')]
    public ?int $linkedLeaveId = null;

    /** C2: ?new=1 (the header's New button) opens the new-request dialog once. */
    #[Url(as: 'new', except: false)]
    public bool $startNew = false;

    public string $statusFilter = 'all';

    public array $counts = [];

    /**
     * The request this page just sent or updated, shown in the result panel
     * with its real status and next step. Locked: the browser cannot point
     * it at another id (and submittedLeave() only reads own requests anyway).
     */
    #[Locked]
    public ?int $submittedLeaveId = null;

    #[Locked]
    public ?string $submittedKind = null;

    protected $listeners = [
        'openRequestLeaveEdit' => 'edit',
        'openMyLeaveDetails' => 'openDetails',
        'openRequestLeaveCreate' => 'create',
        'leaveStatusFilterCleared' => 'resetStatusFilter',
        'processSave' => 'processSave',
    ];

    public function mount()
    {
        $this->refreshCounts();

        if ($this->linkedLeaveId !== null) {
            $this->openDetails($this->linkedLeaveId);
        }

        if ($this->startNew) {
            $this->startNew = false;
            $this->create();
        }
    }

    public function setActiveTab(string $status)
    {
        $this->statusFilter = $status;
        $this->dispatch('$refresh');
    }

    /** The table's "clear filters" when only the status tab was narrowing the list. */
    public function resetStatusFilter(): void
    {
        $this->setActiveTab('all');
    }

    public function refreshCounts(): void
    {
        $user = auth()->user();
        $employee = $user->profile;

        $leaveRequests = LeaveRequest::whereHas('leaveBalance.contract', function ($query) use ($employee) {
            $query->where('employee_id', $employee->id);
        });

        // A failed count must not take the page down: the table below shows the
        // load error with its retry, and the tabs fall back to zero until it recovers.
        try {
            $this->counts = [
                'all' => (clone $leaveRequests)?->count(),
                'pending' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Pending)?->count(),
                'accepted' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Accepted)?->count(),
                'rejected' => (clone $leaveRequests)->where('status', LeaveRequestStatus::Rejected)?->count(),
            ];
        } catch (Throwable $e) {
            report($e);
            $this->counts = ['all' => 0, 'pending' => 0, 'accepted' => 0, 'rejected' => 0];
        }
    }

    public function create()
    {
        $this->authorizeActivePermission('leaves.create');

        $this->form->reset();
        $this->form->resetValidation();
        // One token per opened form: a retried or double-sent save of this
        // form returns the request it already created (LeaveRequestForm::store).
        $this->form->submission_token = (string) Str::uuid();
        $this->markClean();
        $this->modalTitle = __('leaves.modal.new-title');
        $this->showModal = true;
        // X2-I task A: one attempt per opened new-request form.
        $this->startTaskAttempt('leave_request');
    }

    public function edit(LeaveRequest $id)
    {
        $this->authorizeActivePermission('leaves.update');
        Gate::authorize('update', $id);

        $this->form->resetValidation();
        $this->form->setLeaveRequest($id);

        $this->markClean();
        $this->modalTitle = __('leaves.modal.edit-title');
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

    #[Computed]
    public function submittedLeave(): ?LeaveRequest
    {
        if ($this->submittedLeaveId === null) {
            return null;
        }

        return $this->ownedLeaveRequests()
            ->with(['leaveBalance.leaveType', 'leaveBalance.contract.employee.city'])
            ->find($this->submittedLeaveId);
    }

    /**
     * The live impact summary beside the dates (A3 step 2), worded for the
     * view. Priced on the server from the typed fields on every render by
     * LeaveRequestPricing — nothing here is stored, and computed_hours is
     * never taken from the browser.
     *
     * Owner only (§9): the balance must belong to the signed-in employee's
     * own active contract, whatever form.employee_id says. Never pay data.
     * A partial or invalid form gives ['ready' => false] (a neutral hint);
     * this never throws.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function leaveImpact(): ?array
    {
        $employee = auth()->user()?->profile;

        if (! $this->showModal || ! $employee) {
            return null;
        }

        $quote = $this->form->previewQuote($employee);

        return $quote ? $this->describeQuote($quote) : ['ready' => false];
    }

    /**
     * Only annual leave can be taken by the hour. Switching to another type
     * after picking "Hour" used to keep type = hour while the form showed the
     * date fields, so the save failed on hidden fields. The typed values stay;
     * only the unit goes back to days.
     */
    public function updatedFormLeaveBalanceId($value): void
    {
        if ($this->form->type !== 'hour' || blank($value)) {
            return;
        }

        $balance = auth()->user()->profile?->latestActiveContract()
            ?->leaveBalances()
            ->with('leaveType')
            ->find($value);

        if ($balance && ! $balance->isAnnual()) {
            $this->form->type = 'day';
        }
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

        // A failed balance lookup shows a retry panel in place of the cards
        // instead of a 500; the request list below has its own failure state.
        [$availableLeaves, $allAvailableLeaves, $alternativeEmployees] = $this->guardedLoad(fn () => [
            $employee->availableLeaves(),
            $employee->allAvailableLeaves(),
            app(LeaveAlternativeEmployees::class)->for($employee),
        ], fallback: [null, collect(), collect()]);

        return view('livewire.employee.leave.index', compact(
            'availableLeaves', 'allAvailableLeaves', 'alternativeEmployees',
        ));
    }

    public function confirmSave()
    {
        // Validate first: the confirmation needs a chosen leave balance, and
        // without this an empty form only reported "leave type is invalid"
        // instead of each missing field.
        $this->form->validate();
        // Own overlap is reported on the start field before asking, and
        // checked again under a lock when the request is written.
        $this->form->ensureNoOwnOverlap();
        // So are the pricing errors (balance, December, working hours ...),
        // each on its own field, from the same service as the preview.
        $quote = $this->form->checkedQuote();

        $html = $this->confirmationMessage($quote);

        $this->confirm('processSave', title: __('general.confirmation'), html: $html, confirmLabel: __('general.save'), danger: false);

    }

    public function processSave()
    {
        $this->authorizeActivePermission($this->form->id ? 'leaves.update' : 'leaves.create');

        if ($this->form->id) {
            Gate::authorize('update', $this->form->leaveRequest);
            $leaveRequest = $this->form->leaveRequest;
            $this->form->update();

            $this->finishSave($leaveRequest, 'updated');

            return;
        }

        $wasOpen = $this->showModal;
        $token = $this->form->submission_token;

        $this->form->store();
        $leaveRequest = $this->form->leaveRequest;
        $this->finishTaskAttempt();

        if ($this->form->wasReplayed()) {
            if (! $wasOpen) {
                // A second click that reached the server after the first one
                // had already closed the form: nothing more to do or say.
                $this->keepConsumedToken($token);

                return;
            }
        } else {
            // Only after the row is committed: a rolled-back request must not
            // have told anyone about itself.
            DB::afterCommit(fn () => app(LeaveNotificationService::class)->sendNewRequest(
                $leaveRequest,
                auth()->user(),
            ));
            $this->dispatch('notification-created');
        }

        $this->finishSave($leaveRequest, 'sent');
        $this->keepConsumedToken($token);
    }

    /**
     * X1-I: the browser lost the answer to processSave and asks whether the
     * operation was applied. A lookup by the form's submission token: it never
     * writes, so it cannot create a second request. The typed entries stay
     * unless the request is confirmed saved.
     */
    public function reconcileOperation(): void
    {
        $this->authorizeActivePermission('leaves.create');

        $token = $this->form->submission_token;
        $leaveRequest = $this->form->id ? null : $this->form->consumedRequest();

        if ($leaveRequest === null) {
            $this->toast(__('general.unknown-result.not-applied'), type: 'info');

            return;
        }

        if (! $this->showModal) {
            $this->toast(__('general.unknown-result.applied'));

            return;
        }

        $this->finishSave($leaveRequest, 'sent');
        $this->keepConsumedToken($token);
    }

    /** The request dialog's entries, for the close guard. */
    protected function guardedEntries(string $dialog): array
    {
        return $this->form->entries();
    }

    protected function closeGuardedDialog(string $dialog): void
    {
        $this->showModal = false;
        $this->form->reset();
        $this->form->resetValidation();

        // C2: a request started from New and closed unsent goes back to where New was pressed.
        if ($this->returnTo !== null) {
            $this->redirect($this->returnTo, navigate: true);
        }
    }

    protected function confirmationMessage(?LeaveQuote $quote): string
    {
        $balance = $this->form->selectedBalance();
        $leaveTypeName = $balance->leaveType->name ?? '—';
        $type = $this->form->type;

        $start = $this->resolveStart();
        $end = $this->resolveEnd();
        $impact = $quote ? $this->describeQuote($quote) : null;
        $duration = $impact['requested'] ?? '—';
        $projected = $impact['projected'] ?? null;
        $warnings = $impact['warnings'] ?? [];
        $date = $this->form->leave_date;
        $reason = $this->form->reason;

        return Blade::render(
            '<x-leave.request-confirmation
                :type="$type"
                :leave-type="$leaveTypeName"
                :start="$start"
                :end="$end"
                :duration="$duration"
                :projected="$projected"
                :warnings="$warnings"
                :reason="$reason"
                :date="$date"
            />',
            compact('type', 'leaveTypeName', 'start', 'end', 'duration', 'projected', 'warnings', 'reason', 'date'),
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

    /**
     * The quote as view-ready strings: day/hour/minute wording with the
     * schedule's own day length, translated codes and reasons. Hours and
     * dates only.
     *
     * @return array<string, mixed>
     */
    protected function describeQuote(LeaveQuote $quote): array
    {
        $perDay = $quote->hoursPerDay;
        $amount = fn (float $hours): string => $this->formatLeaveSplit(abs($hours), $perDay);
        $short = $quote->projectedHours < -0.005;
        $locale = app()->getLocale();

        return [
            'ready' => true,
            'entitled' => ! $quote->hasError(LeaveQuote::NO_LEAVE_ENTITLEMENT),
            'hourly' => $this->form->type === 'hour',
            'calendar_days' => $quote->calendarDays,
            'available' => ($quote->availableHours < 0 ? '−' : '').$amount($quote->availableHours),
            'pending' => $amount($quote->pendingHours),
            'has_pending' => $quote->pendingHours > 0,
            'requested' => $amount($quote->requestedHours),
            'requested_hours' => $this->formatLeaveHours($quote->requestedHours),
            'requested_value' => $quote->requestedHours,
            'counted_days' => $this->formatLeaveDays($quote->countedDays),
            'projected' => $short
                ? __('leave_quote.preview.short', ['amount' => $amount($quote->projectedHours)])
                : $amount($quote->projectedHours),
            'short' => $short,
            'excluded' => array_map(fn (array $excluded): array => [
                'date' => Carbon::parse($excluded['date'])->format('d-m-Y'),
                'day' => Carbon::parse($excluded['date'])->locale($locale)->translatedFormat('l'),
                'reason' => __('leave_quote.reasons.'.$excluded['reason']),
            ], $quote->excludedDates),
            'warnings' => array_map(fn (string $code): string => __(LeaveQuote::messageKey($code)), $quote->warnings),
            'errors' => array_map(fn (string $code): string => __(LeaveQuote::messageKey($code)), $quote->errors),
            'explanation' => array_map(fn (array $line): string => __($line['key'], $line['params']), $quote->explanation),
            'team' => $this->teamHint(),
        ];
    }

    /**
     * C5 (§4.5 team_overlap_hint, manager_absent): from TeamCoverage, the
     * reader the team calendar uses. A count of colleagues already away on an
     * accepted leave, never their names, and whether the direct manager is
     * away. Advisory: it never blocks the request. Null when it cannot be
     * read, so the preview still shows.
     *
     * @return array{colleagues: int, manager_away: bool}|null
     */
    protected function teamHint(): ?array
    {
        try {
            $window = $this->form->window();

            if ($window === null || $window->endDate->lt($window->startDate)) {
                return null;
            }

            $from = $window->startDate;
            $to = $window->endDate;

            if ($from->diffInDays($to) + 1 > (int) config('team-coverage.max_days')) {
                return null;
            }

            $coverage = app(TeamCoverage::class);
            $user = auth()->user();

            return [
                'colleagues' => $coverage->colleaguesAwayCount($user, $from, $to),
                'manager_away' => $coverage->managerAway($user, $from, $to),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function finishSave(LeaveRequest $leaveRequest, string $kind): void
    {
        $this->showModal = false;
        $this->form->reset();

        $leaveRequest->refresh();
        $this->submittedLeaveId = $leaveRequest->id;
        $this->submittedKind = $kind;
        unset($this->submittedLeave);

        $this->toast(__('leaves.submit.'.$kind, [
            'status' => LeaveRequestStatus::getLocalizedName((int) $leaveRequest->status)['value'],
        ]));

        $this->refreshCounts();
        $this->dispatch('leaveRequestSaved')->to(MyLeaveRequestTable::class);
    }

    /**
     * After a successful create the form is empty but keeps its used token,
     * so a late duplicate of the same save replays instead of failing
     * validation against the emptied form. create() issues a fresh one.
     */
    private function keepConsumedToken(?string $token): void
    {
        $this->form->reset();
        $this->form->submission_token = $token;
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
