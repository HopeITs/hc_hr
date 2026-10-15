<?php

namespace App\Livewire\Employee;

use App\Models\Badge;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EmployeeProfile extends Component
{
    public Employee $employee;

    #[Locked]
    public bool $isSelf = false;

    #[Locked]
    public bool $canViewPrivate = false;

    #[Locked]
    public bool $canViewFinancial = false;

    #[Locked]
    public bool $canEdit = false;

    #[Locked]
    public bool $canViewBadges = false;

    // Contracts, education, experience, skills, courses and badges. Hidden from
    // the employee role on every profile, their own included.
    #[Locked]
    public bool $canViewRecords = false;

    public function mount(?Employee $employee = null): void
    {
        $this->employee = $employee?->exists
            ? $employee
            : Auth::user()?->profile ?? abort(404);

        /** @var User|null $user */
        $user = Auth::user();
        abort_unless($user, 403);

        $this->isSelf = $user->employee_id === $this->employee->id;
        $isHr = $user->activeRoleIs('HR', 'super-admin');
        $isManagement = $user->activeRoleIs('general-manager');
        $isHrInScope = $isHr && $user->canAccessCity($this->employee->city_id);

        $this->canViewPrivate = $this->isSelf || $isHrInScope;

        // Narrower than $isHrInScope on purpose: $isHr above counts
        // super-admin as HR so a developer can open any profile, and pay is
        // the one thing that must not come with that. See User::canViewSalary.
        $this->canViewFinancial = $isHrInScope && $user->canViewSalary();
        $this->canEdit = $isHrInScope || $isManagement;
        $this->canViewRecords = ! $user->activeRoleIs('employee');
        // Same rule as the Badges page and the dashboard card, through
        // Badge::scopeVisibleTo(): regional HR its own city, HR-SYR and
        // general-manager every city, a manager granted badges.view their
        // reports. Being the profile's owner grants nothing by itself.
        $this->canViewBadges = $this->canViewRecords
            && Gate::forUser($user)->allows('viewForEmployee', [Badge::class, $this->employee]);
    }

    public function render()
    {
        $contractColumns = [
            'id', 'employee_id', 'position_id', 'contract_type_id',
            'termination_reason_id', 'start_date', 'end_date', 'terminated_at',
            'weekly_hours', 'salary_per', 'is_active',
        ];

        if ($this->canViewFinancial) {
            array_push($contractColumns, 'salary', 'currency');
        }

        $relations = [
            'account',
            // Without the history the header and summary still need the
            // current contract, so only that one is loaded.
            'allContracts' => fn ($query) => $query
                ->select($contractColumns)
                ->unless($this->canViewRecords, fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('terminated_at')
                    ->whereDate('start_date', '<=', today())
                    ->whereDate('end_date', '>=', today()))
                ->with([
                    'contractType',
                    'terminationReason',
                    'position.center',
                    'position.program',
                    'position.city',
                    'position.jobLevel',
                    'position.department',
                    'position.directManager.activeContracts.employee',
                ])
                ->latest('start_date'),
        ];

        if ($this->canViewRecords) {
            array_push($relations, 'educations', 'experiences', 'courses.category');
        }

        if ($this->canViewBadges) {
            $relations['badges'] = fn ($query) => $query
                ->select($this->canViewFinancial
                    ? ['id', 'employee_id', 'evaluation_id', 'currency', 'type', 'date', 'reason', 'current_salary', 'new_salary', 'notes']
                    : ['id', 'employee_id', 'evaluation_id', 'type', 'date', 'reason', 'notes'])
                ->latest('date');
        }

        $employee = $this->employee->load($relations);

        if ($this->canViewPrivate) {
            $employee->load('relatives');
        }

        $contracts = $employee->allContracts;
        $currentContract = $contracts->first(fn ($contract) => $contract->is_active
            && $contract->start_date?->lte(today())
            && $contract->end_date?->gte(today())
            && ! $contract->terminated_at);
        $currentPosition = $currentContract?->position;

        return view('livewire.employee.employee-profile', [
            'employee' => $employee,
            'contracts' => $contracts,
            'currentContract' => $currentContract,
            'currentPosition' => $currentPosition,
            'isOnLeave' => $employee->is_active && $employee->isOnLeaveToday(),
            'directManager' => $currentPosition?->directManager?->activeContracts?->first()?->employee,
            'skills' => $this->canViewRecords ? $this->parseTags($employee->skills) : [],
            'interests' => $this->canViewRecords ? $this->parseTags($employee->interests) : [],
            'canViewPrivate' => $this->canViewPrivate,
            'canViewFinancial' => $this->canViewFinancial,
            'canEdit' => $this->canEdit,
            'canViewBadges' => $this->canViewBadges,
            'canViewRecords' => $this->canViewRecords,
        ]);
    }

    private function parseTags(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);
        $tags = is_array($decoded) ? $decoded : explode(',', $value);

        return array_values(array_filter(array_map(
            fn ($tag) => trim((string) $tag),
            $tags,
        )));
    }
}
