<?php

namespace App\Livewire\Employee;

use App\Livewire\Concerns\WithFeedback;
use App\Models\Employee;
use App\Models\EmployeeEntitlement;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A person's Permissions Library items on their profile (meeting 19/11,
 * track 5): what they should have outside the system, what was done and what
 * was taken back. Shown only to HR of their city and to their direct manager
 * (Entitlements::canAssign); for anyone else it renders nothing. Every action
 * re-checks on the server through the service.
 */
class EntitlementsPanel extends Component
{
    use WithFeedback;

    #[Locked]
    public int $employeeId;

    public string $addId = '';

    public string $note = '';

    public function mount(Employee $employee): void
    {
        $this->employeeId = $employee->id;
    }

    public function add(Entitlements $entitlements): void
    {
        $employee = $this->authorizedEmployee($entitlements);
        $this->validate([
            'addId' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $entitlement = Entitlement::query()->active()->findOrFail((int) $this->addId);
        $added = $entitlements->add($employee, $entitlement, $this->user(), trim($this->note) ?: null);

        $this->reset('addId', 'note');
        $this->toast($added ? __('entitlements.added') : __('entitlements.already'), $added ? 'success' : 'info');
    }

    public function markGranted(int $rowId, Entitlements $entitlements): void
    {
        $changed = $entitlements->markGranted($this->row($rowId, $entitlements), $this->user());
        $this->toast($changed ? __('entitlements.granted_done') : __('entitlements.nothing_changed'), $changed ? 'success' : 'info');
    }

    public function revoke(int $rowId, Entitlements $entitlements): void
    {
        $changed = $entitlements->revoke($this->row($rowId, $entitlements), $this->user());
        $this->toast($changed ? __('entitlements.revoked_done') : __('entitlements.nothing_changed'), $changed ? 'success' : 'info');
    }

    public function keep(int $rowId, Entitlements $entitlements): void
    {
        $changed = $entitlements->keep($this->row($rowId, $entitlements), $this->user());
        $this->toast($changed ? __('entitlements.kept') : __('entitlements.nothing_changed'), $changed ? 'success' : 'info');
    }

    public function render(Entitlements $entitlements)
    {
        $employee = Employee::query()->findOrFail($this->employeeId);
        $allowed = $entitlements->canAssign(Auth::user(), $employee);
        $rows = $allowed ? $entitlements->forEmployee($employee) : collect();

        $openIds = $rows->where('state', '!=', EmployeeEntitlement::STATE_REVOKED)->pluck('entitlement_id')->all();

        return view('livewire.employee.entitlements-panel', [
            'allowed' => $allowed,
            'rows' => $rows,
            'available' => $allowed
                ? Entitlement::query()->active()->whereNotIn('id', $openIds)->orderBy(transColumn('name'))->get()
                : collect(),
        ]);
    }

    // ── internals ─────────────────────────────────────────────────────

    /** Re-checked on every action: a role switch or a team change since mount counts. */
    private function authorizedEmployee(Entitlements $entitlements): Employee
    {
        $employee = Employee::query()->findOrFail($this->employeeId);
        abort_unless($entitlements->canAssign(Auth::user(), $employee), 403);

        return $employee;
    }

    /** Only a row of this panel's person, never an id from another one. */
    private function row(int $rowId, Entitlements $entitlements): EmployeeEntitlement
    {
        $employee = $this->authorizedEmployee($entitlements);

        return EmployeeEntitlement::query()->where('employee_id', $employee->id)->findOrFail($rowId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
