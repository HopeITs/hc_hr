<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\Badge;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeTimeline;
use App\Support\Dashboard\DashboardLayout;
use App\Support\DirectoryAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One section of a person's profile as a dashboard card (DashboardLayout::
 * RECORDS: overview, timeline, education, experience, skills, courses, badges,
 * personal, personal-contact, family), added with "Add to dashboard" on that
 * section. Each section is behind the same EmployeePolicy ability the profile
 * draws it with (RECORDS' last column), asked again on every render; pay only
 * where the profile shows it (HR in the person's city with salary visibility).
 */
class EmployeeSectionCard extends Component
{
    use HandlesLoadFailure;

    #[Locked]
    public int $employeeId;

    #[Locked]
    public string $section = '';

    public function placeholder(array $params = []): View
    {
        $key = DashboardLayout::recordKey((string) ($params['section'] ?? ''), (int) ($params['employeeId'] ?? 0));

        return view('livewire.dashboard.card-placeholder', ['title' => app(DashboardLayout::class)->label($key)]);
    }

    public function render()
    {
        $user = Auth::user();
        $spec = DashboardLayout::RECORDS[$this->section] ?? null;
        $employee = Employee::query()->find($this->employeeId);
        $allowed = $user && $employee && $spec !== null && $spec[4] === self::class && Gate::allows($spec[5], $employee);

        return view('livewire.dashboard.employee-section-card', [
            'employee' => $allowed ? $employee : null,
            'data' => $allowed ? $this->guardedLoad(fn () => $this->load($user, $employee), fallback: []) : [],
        ]);
    }

    /** @return array<string, mixed> what the section's view draws */
    private function load(User $user, Employee $employee): array
    {
        return match ($this->section) {
            'overview' => $this->overview($user, $employee),
            'timeline' => ['events' => $this->timeline($user, $employee)],
            'education' => ['items' => $employee->educations()->get()],
            'experience' => ['items' => $employee->experiences()->get()],
            'skills' => ['skills' => self::tags($employee->skills), 'interests' => self::tags($employee->interests)],
            'courses' => ['items' => $employee->courses()->limit(5)->get()],
            'badges' => ['items' => $this->badges($user, $employee)->take(5)],
            'personal', 'personal-contact' => [],
            'family' => ['items' => $employee->relatives()->get()],
            default => [],
        };
    }

    /** The profile's employment overview: the current contract's position, read like the profile does. */
    private function overview(User $user, Employee $employee): array
    {
        $contract = $employee->allContracts()
            ->where('is_active', true)
            ->whereNull('terminated_at')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->with([
                'position.jobLevel',
                'position.program',
                'position.city',
                // The manager is a Directory entry: a former one stays unnamed for anyone who may not see them.
                'position.directManager.activeContracts' => DirectoryAccess::holders($user),
                'position.directManager.activeContracts.employee' => DirectoryAccess::holderEmployee(),
            ])
            ->reorder('start_date', 'desc')
            ->first(['contracts.id', 'contracts.employee_id', 'contracts.position_id', 'contracts.start_date', 'contracts.end_date', 'contracts.terminated_at', 'contracts.is_active']);

        $position = $contract?->position;

        return [
            'position' => $position?->title,
            'jobLevel' => $position?->jobLevel?->name,
            'manager' => $position?->directManager?->activeContracts?->first()?->employee?->name,
            'program' => $position?->program?->name,
            'city' => $position?->city?->name,
        ];
    }

    /** The profile's timeline: career events and the badges this viewer may see, pay only where the profile shows it. */
    private function timeline(User $user, Employee $employee): array
    {
        $financial = $this->canViewPay($user, $employee);
        $columns = ['id', 'employee_id', 'position_id', 'contract_type_id', 'termination_reason_id', 'weekly_hours', 'start_date', 'end_date', 'terminated_at', 'is_active'];

        if ($financial) {
            array_push($columns, 'salary', 'currency', 'salary_per');
        }

        $contracts = $employee->allContracts()
            ->select($columns)
            ->with(['position.department', 'contractType', 'terminationReason'])
            ->reorder('start_date', 'desc')
            ->get();

        return array_slice(app(EmployeeTimeline::class)->events($contracts, $this->badges($user, $employee), $financial), 0, 12);
    }

    /** Same as the profile: the Badges page's rule, else the owner's own rewards and warnings. */
    private function badges(User $user, Employee $employee): Collection
    {
        if (Gate::forUser($user)->allows('viewForEmployee', [Badge::class, $employee])) {
            return $employee->badges()
                ->select($this->canViewPay($user, $employee)
                    ? ['id', 'employee_id', 'evaluation_id', 'currency', 'type', 'date', 'reason', 'current_salary', 'new_salary', 'notes']
                    : ['id', 'employee_id', 'evaluation_id', 'type', 'date', 'reason', 'notes'])
                ->latest('date')
                ->get();
        }

        return (int) $user->employee_id === (int) $employee->id
            ? app(EmployeeTimeline::class)->ownBadges($employee)
            : collect();
    }

    /** EmployeeProfile's canViewFinancial: HR (super-admin counted) in the person's city, and User::canViewSalary(). */
    private function canViewPay(User $user, Employee $employee): bool
    {
        return $user->activeRoleIs('HR', 'super-admin') && $user->canAccessCity($employee->city_id) && $user->canViewSalary();
    }

    /** @return list<string> a stored tag list (JSON or comma separated), as the profile reads it */
    private static function tags(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);
        $tags = is_array($decoded) ? $decoded : explode(',', $value);

        return array_values(array_filter(array_map(fn ($tag) => trim((string) $tag), $tags)));
    }
}
