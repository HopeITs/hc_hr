<?php

namespace App\Livewire\Employee;

use App\Livewire\Concerns\WithFeedback;
use App\Models\Employee;
use App\Services\OnboardingJourneys;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A new volunteer's onboarding journey on their edit page (D4-I, DEC-09;
 * docs/ux/journeys.md): five steps, each with its owner, state, due date and
 * what blocks it. Every action re-authorizes as EmployeePolicy::update (HR
 * for the volunteer's city) and goes through OnboardingJourneys, which owns
 * the writes and the audit log.
 */
class OnboardingJourneyPanel extends Component
{
    use WithFeedback;

    #[Locked]
    public int $employeeId;

    public string $orientationDate = '';

    public function mount(Employee $employee): void
    {
        Gate::authorize('update', $employee);

        $this->employeeId = $employee->id;
        $this->orientationDate = $employee->onboardingJourney?->orientation_scheduled_for?->toDateString() ?? '';
    }

    public function scheduleOrientation(OnboardingJourneys $journeys): void
    {
        $employee = $this->authorizedEmployee();

        $this->validate(
            ['orientationDate' => ['required', 'date_format:Y-m-d']],
            attributes: ['orientationDate' => __('employees.journey.orientation-date')],
        );

        $changed = $journeys->scheduleOrientation($employee, Carbon::createFromFormat('Y-m-d', $this->orientationDate), auth()->user());

        $this->toast($changed ? __('employees.journey.orientation-scheduled') : __('employees.journey.nothing-changed'), $changed ? 'success' : 'info');
    }

    public function completeOrientation(OnboardingJourneys $journeys): void
    {
        $changed = $journeys->completeOrientation($this->authorizedEmployee(), auth()->user());

        $this->toast($changed ? __('employees.journey.orientation-done') : __('employees.journey.nothing-changed'), $changed ? 'success' : 'info');
    }

    public function markCardHandedOver(OnboardingJourneys $journeys): void
    {
        $changed = $journeys->markCardHandedOver($this->authorizedEmployee(), auth()->user());

        $this->toast($changed ? __('employees.journey.card-done') : __('employees.journey.nothing-changed'), $changed ? 'success' : 'info');
    }

    /** Ask first: undoing a step is recorded, and the journey reopens. */
    public function confirmReopen(string $step): void
    {
        $this->authorizedEmployee();
        abort_unless(in_array($step, ['card', 'orientation'], true), 404);

        $this->confirm(
            'reopen',
            payload: $step,
            title: __('employees.journey.reopen-title'),
            message: __('employees.journey.reopen-message', ['step' => __("employees.journey.step.{$step}")]),
            confirmLabel: __('employees.journey.reopen'),
            danger: false,
        );
    }

    public function reopen(string $step, OnboardingJourneys $journeys): void
    {
        abort_unless(in_array($step, ['card', 'orientation'], true), 404);

        $changed = $journeys->reopen($this->authorizedEmployee(), $step, auth()->user());

        $this->toast($changed ? __('employees.journey.reopened') : __('employees.journey.nothing-changed'), $changed ? 'success' : 'info');
    }

    /** The edit page saved the record (e.g. a draft completed): show the new state. */
    #[On('form-saved')]
    public function refreshJourney(): void {}

    public function render(OnboardingJourneys $journeys)
    {
        $employee = Employee::query()->with(['onboardingJourney.contract', 'city'])->findOrFail($this->employeeId);

        return view('livewire.employee.onboarding-journey-panel', [
            'employee' => $employee,
            'journey' => $employee->onboardingJourney,
            'steps' => $journeys->steps($employee),
            'canViewContracts' => auth()->user()?->hasActivePermission('contracts.view') ?? false,
            'canCreateContracts' => auth()->user()?->hasActivePermission('contracts.create') ?? false,
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
