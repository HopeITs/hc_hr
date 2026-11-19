<?php

namespace App\Livewire\Onboarding;

use App\Livewire\Concerns\WithFeedback;
use App\Models\Employee;
use App\Services\OnboardingJourneys;
use Carbon\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The direct manager's orientation session with a new volunteer (track 7):
 * the manager sets the date, then records it once it has happened. Who may act
 * is OnboardingJourneys::canRunManagerOrientation (the direct manager, HR of
 * the volunteer's city as the fallback), checked on mount and on every action.
 */
class ManagerSession extends Component
{
    use WithFeedback;

    #[Locked]
    public int $employeeId;

    public string $date = '';

    public function mount(Employee $employee, OnboardingJourneys $journeys): void
    {
        abort_unless($employee->onboardingJourney !== null, 404);
        abort_unless($journeys->canRunManagerOrientation(auth()->user(), $employee), 403);

        $this->employeeId = $employee->id;
        $this->date = $employee->onboardingJourney->manager_orientation_scheduled_for?->toDateString() ?? '';
    }

    public function schedule(OnboardingJourneys $journeys): void
    {
        $employee = $this->authorizedEmployee($journeys);

        $this->validate(
            ['date' => ['required', 'date_format:Y-m-d']],
            attributes: ['date' => __('onboarding.manager-session.date')],
        );

        $changed = $journeys->scheduleManagerOrientation($employee, Carbon::createFromFormat('Y-m-d', $this->date), auth()->user());

        $this->toast($changed ? __('onboarding.manager-session.scheduled') : __('employees.journey.nothing-changed'), $changed ? 'success' : 'info');
    }

    public function complete(OnboardingJourneys $journeys): void
    {
        $changed = $journeys->completeManagerOrientation($this->authorizedEmployee($journeys), auth()->user());

        $this->toast($changed ? __('onboarding.manager-session.done') : __('employees.journey.nothing-changed'), $changed ? 'success' : 'info');
    }

    public function render(): View
    {
        $employee = Employee::query()->with(['onboardingJourney', 'city'])->findOrFail($this->employeeId);
        $journey = $employee->onboardingJourney;

        abort_unless(app(OnboardingJourneys::class)->canRunManagerOrientation(auth()->user(), $employee), 403);

        return view('livewire.onboarding.manager-session', [
            'employee' => $employee,
            'journey' => $journey,
            'canComplete' => $journey?->manager_orientation_scheduled_for !== null
                && $journey->manager_orientation_scheduled_for->lte(now(config('app.timezone'))->startOfDay()),
        ])->title(__('onboarding.manager-session.title'));
    }

    private function authorizedEmployee(OnboardingJourneys $journeys): Employee
    {
        $employee = Employee::query()->findOrFail($this->employeeId);
        abort_unless($journeys->canRunManagerOrientation(auth()->user(), $employee), 403);

        return $employee;
    }
}
