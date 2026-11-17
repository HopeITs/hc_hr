<?php

namespace App\Livewire\Employee;

use App\Livewire\Concerns\WithFeedback;
use App\Models\Employee;
use App\Models\RenewalJourney;
use App\Services\RenewalJourneys;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The employee's renewal journeys on their edit page (DEC-09 increment 2;
 * docs/ux/journeys.md §3.2): for each contract ending within 30 days, the
 * expiry warning, HR's decision (renew or stop), the new contract and its
 * leave balances. Every action re-authorizes as
 * ContractPolicy::manageAutoRenew on the journey's contract (HR active role,
 * contracts.update, the contract in the user's city) and goes through
 * RenewalJourneys, which owns the writes and the audit log.
 *
 * Pay is never read here: no figure is loaded into the view or the payload.
 */
class RenewalJourneyPanel extends Component
{
    use WithFeedback;

    #[Locked]
    public int $employeeId;

    public function mount(Employee $employee): void
    {
        Gate::authorize('update', $employee);

        $this->employeeId = $employee->id;
    }

    /** Let it renew: records the decision; auto-renew stays as it is. */
    public function decideRenew(int $journeyId, RenewalJourneys $journeys): void
    {
        $changed = $journeys->decide($this->authorizedJourney($journeyId), RenewalJourney::DECISION_RENEW, auth()->user());

        $this->toast($changed ? __('renewal.decided-renew') : __('renewal.nothing-changed'), $changed ? 'success' : 'info');
    }

    /** Ask first: stopping switches auto-renew off and starts equipment returns. */
    public function confirmStop(int $journeyId): void
    {
        $this->authorizedJourney($journeyId);

        $this->confirm(
            'stop',
            payload: $journeyId,
            title: __('renewal.stop-title'),
            message: __('renewal.stop-message'),
            confirmLabel: __('renewal.stop'),
            danger: true,
        );
    }

    public function stop(int $journeyId, RenewalJourneys $journeys): void
    {
        $changed = $journeys->decide($this->authorizedJourney($journeyId), RenewalJourney::DECISION_STOP, auth()->user());

        $this->toast($changed ? __('renewal.decided-stop') : __('renewal.nothing-changed'), $changed ? 'success' : 'info');
    }

    /** Ask first: an undo is recorded, and a stop's auto-renew switch is put back. */
    public function confirmUndo(int $journeyId): void
    {
        $this->authorizedJourney($journeyId);

        $this->confirm(
            'undo',
            payload: $journeyId,
            title: __('renewal.undo-title'),
            message: __('renewal.undo-message'),
            confirmLabel: __('renewal.undo'),
            danger: false,
        );
    }

    public function undo(int $journeyId, RenewalJourneys $journeys): void
    {
        $changed = $journeys->undo($this->authorizedJourney($journeyId), auth()->user());

        $this->toast($changed ? __('renewal.undone') : __('renewal.nothing-changed'), $changed ? 'success' : 'info');
    }

    /** The edit page saved something: show the current state. */
    #[On('form-saved')]
    public function refreshJourneys(): void {}

    public function render(RenewalJourneys $journeys)
    {
        $employee = Employee::query()->findOrFail($this->employeeId);
        $user = auth()->user();

        // Only the journeys whose decision this user may take: the same rule
        // every action re-checks. Anyone else sees no section at all.
        $rows = $journeys->forEmployee($employee)
            ->filter(fn (RenewalJourney $journey) => $journey->contract && $user?->can('manageAutoRenew', $journey->contract))
            ->map(fn (RenewalJourney $journey) => [
                'journey' => $journey,
                'steps' => $journeys->steps($journey),
                'canRenew' => $user->can('renew', $journey->contract),
            ])
            ->values();

        return view('livewire.employee.renewal-journey-panel', [
            'rows' => $rows,
            'canViewContracts' => $user?->hasActivePermission('contracts.view') ?? false,
        ]);
    }

    /**
     * Re-checked on every action: a role switch or a city change since mount
     * counts. The journey must belong to this panel's employee.
     */
    private function authorizedJourney(int $journeyId): RenewalJourney
    {
        $journey = RenewalJourney::query()
            ->where('employee_id', $this->employeeId)
            ->with('contract')
            ->findOrFail($journeyId);

        abort_unless($journey->contract !== null, 404);
        Gate::authorize('manageAutoRenew', $journey->contract);

        return $journey;
    }
}
