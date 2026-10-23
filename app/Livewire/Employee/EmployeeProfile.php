<?php

namespace App\Livewire\Employee;

use App\Models\Badge;
use App\Models\Employee;
use App\Models\User;
use App\Services\TaskTelemetry;
use App\Support\DirectoryAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The full profile page. Who may open it, and which sections they get, is
 * docs/ux/access-policy.md §4.3 (signed off 2026-10-20):
 *
 * - D (header, position, work contact, manager): EmployeePolicy::viewDirectory.
 *   Anyone else gets the same 404 as an unknown username (§4.4).
 * - R (career history without pay, education, experience, skills, courses):
 *   EmployeePolicy::viewRecords — self, a manager's direct reports, the
 *   general manager, HR in city scope, HR-SYR, super-admin.
 * - P (identity, personal contact, family): self or HR in city scope.
 * - F (pay): HR in city scope and User::canViewSalary().
 * - Badges: BadgePolicy::viewForEmployee (Badge::scopeVisibleTo).
 *
 * Sections a viewer may not see are not loaded at all, rather than loaded and
 * hidden. The flags are recomputed on every request, not only in mount(), so
 * an active-role switch takes effect on the next request.
 */
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

    // Contracts (without pay), education, experience, skills and courses.
    #[Locked]
    public bool $canViewRecords = false;

    public function mount(?Employee $employee = null): void
    {
        $this->employee = $employee?->exists
            ? $employee
            : Auth::user()?->profile ?? abort(404);

        $this->resolveAccess();

        // X2-I task B: opening someone's profile completes an open lookup.
        if ($employee?->exists) {
            app(TaskTelemetry::class)->completeLookup();
        }
    }

    /** The active role may have changed since the page was opened. */
    public function hydrate(): void
    {
        $this->resolveAccess();
    }

    public function render()
    {
        $contractColumns = [
            'id', 'employee_id', 'position_id', 'start_date', 'end_date',
            'terminated_at', 'is_active',
        ];

        // Contract type, termination reason and weekly hours are career
        // history; the header only needs the current position.
        if ($this->canViewRecords) {
            array_push($contractColumns, 'contract_type_id', 'termination_reason_id', 'weekly_hours');
        }

        if ($this->canViewFinancial) {
            array_push($contractColumns, 'salary', 'currency', 'salary_per');
        }

        $contractRelations = [
            'position.center',
            'position.program',
            'position.city',
            'position.jobLevel',
            'position.department',
            // The manager is a Directory entry: a former one stays unnamed
            // for anyone who may not see former employees.
            'position.directManager.activeContracts' => DirectoryAccess::holders(Auth::user()),
            'position.directManager.activeContracts.employee' => DirectoryAccess::holderEmployee(),
        ];

        if ($this->canViewRecords) {
            array_push($contractRelations, 'contractType', 'terminationReason');
        }

        $relations = [
            DirectoryAccess::ACCOUNT_COLUMNS,
            // Without the history the header and summary still need the
            // current contract, so only that one is loaded.
            'allContracts' => fn ($query) => $query
                ->select($contractColumns)
                ->unless($this->canViewRecords, fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('terminated_at')
                    ->whereDate('start_date', '<=', today())
                    ->whereDate('end_date', '>=', today()))
                ->with($contractRelations)
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
            // Length of service is read off the career history (R).
            'yearsOfService' => $this->canViewRecords ? $employee->years_of_service_formatted : null,
            'skills' => $this->canViewRecords ? $this->parseTags($employee->skills) : [],
            'interests' => $this->canViewRecords ? $this->parseTags($employee->interests) : [],
            'canViewPrivate' => $this->canViewPrivate,
            'canViewFinancial' => $this->canViewFinancial,
            'canEdit' => $this->canEdit,
            'canViewBadges' => $this->canViewBadges,
            'canViewRecords' => $this->canViewRecords,
        ]);
    }

    /**
     * Authorize the page and work out which sections the active role gets.
     * Runs on mount and again on every later request.
     */
    private function resolveAccess(): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        abort_unless($user, 403);

        $gate = Gate::forUser($user);

        // Unknown usernames already 404 in route binding; a profile outside
        // the viewer's directory cell must look exactly the same.
        abort_if($gate->denies('viewDirectory', $this->employee), 404);

        $this->isSelf = (int) $user->employee_id === (int) $this->employee->id;
        $isHr = $user->activeRoleIs('HR', 'super-admin');
        $isManagement = $user->activeRoleIs('general-manager');
        $isHrInScope = $isHr && $user->canAccessCity($this->employee->city_id);

        $this->canViewPrivate = $this->isSelf || $isHrInScope;

        // Narrower than $isHrInScope on purpose: $isHr above counts
        // super-admin as HR so a developer can open any profile, and pay is
        // the one thing that must not come with that. See User::canViewSalary.
        $this->canViewFinancial = $isHrInScope && $user->canViewSalary();
        $this->canEdit = $isHrInScope || $isManagement;
        $this->canViewRecords = $gate->allows('viewRecords', $this->employee);
        // Same rule as the Badges page and the dashboard card, through
        // Badge::scopeVisibleTo(): regional HR its own city, HR-SYR and
        // general-manager every city, a manager granted badges.view their
        // reports. Being the profile's owner grants nothing by itself.
        $this->canViewBadges = $gate->allows('viewForEmployee', [Badge::class, $this->employee]);
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
