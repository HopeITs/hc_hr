<?php

namespace App\Livewire\Employee;

use App\Livewire\Concerns\WithFeedback;
use App\Models\Employee;
use App\Services\OffboardingJourneys;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A departing volunteer's exit journey on their edit page (DEC-09 increment 2;
 * docs/ux/journeys.md §3.3): equipment, contract end, deactivation, access and
 * card, plus the optional exit interview, each with its owner, state, due date
 * and what blocks it. Every action re-authorizes as EmployeePolicy::update (HR
 * for the volunteer's city) and goes through OffboardingJourneys, which owns
 * the writes and the audit log.
 */
class OffboardingJourneyPanel extends Component
{
    use WithFeedback;

    #[Locked]
    public int $employeeId;

    public function mount(Employee $employee): void
    {
        Gate::authorize('update', $employee);

        $this->employeeId = $employee->id;
    }

    public function markExitInterviewHeld(OffboardingJourneys $journeys): void
    {
        $changed = $journeys->markExitInterviewHeld($this->authorizedEmployee(), auth()->user());

        $this->toast($changed ? __('offboarding.interview-done') : __('offboarding.nothing-changed'), $changed ? 'success' : 'info');
    }

    /** Ask first: undoing a step is recorded. */
    public function confirmReopen(string $step): void
    {
        $this->authorizedEmployee();
        abort_unless($step === 'interview', 404);

        $this->confirm(
            'reopen',
            payload: $step,
            title: __('offboarding.reopen-title'),
            message: __('offboarding.reopen-message', ['step' => __("offboarding.step.{$step}")]),
            confirmLabel: __('offboarding.reopen'),
            danger: false,
        );
    }

    public function reopen(string $step, OffboardingJourneys $journeys): void
    {
        abort_unless($step === 'interview', 404);

        $changed = $journeys->reopen($this->authorizedEmployee(), $step, auth()->user());

        $this->toast($changed ? __('offboarding.reopened') : __('offboarding.nothing-changed'), $changed ? 'success' : 'info');
    }

    /** The edit page saved the record (e.g. HR deactivated by hand): show the new state. */
    #[On('form-saved')]
    public function refreshJourney(): void {}

    public function render(OffboardingJourneys $journeys)
    {
        $employee = Employee::query()->with(['latestOffboardingJourney.contract', 'latestOffboardingJourney.exitInterviewBy.profile', 'account'])->findOrFail($this->employeeId);
        $journey = $employee->latestOffboardingJourney;

        return view('livewire.employee.offboarding-journey-panel', [
            'employee' => $employee,
            'journey' => $journey,
            'steps' => $journeys->steps($employee),
            'calledOff' => $journey !== null && $journeys->isCalledOff($journey),
            'canViewContracts' => auth()->user()?->hasActivePermission('contracts.view') ?? false,
        ]);
    }

    /** Re-checked on every action: a role switch or a city change since mount counts. */
    private function authorizedEmployee(): Employee
    {
        $employee = Employee::query()->findOrFail($this->employeeId);
        Gate::authorize('update', $employee);

        return $employee;
    }
}
